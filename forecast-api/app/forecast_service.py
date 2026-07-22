"""Demand forecasting with a robust, dependency-light fallback model."""

from __future__ import annotations

import math
import os

import pandas as pd

from app.schemas import ForecastPoint, ForecastRequest, ForecastResponse


def _build_prophet_model(category_type: str | None):
    from prophet import Prophet

    return Prophet(
        daily_seasonality=False,
        yearly_seasonality=False,
        weekly_seasonality=category_type != "Imported_Korean",
        seasonality_mode="multiplicative" if category_type == "Fresh_Food" else "additive",
        changepoint_prior_scale=0.1 if category_type == "Fresh_Food" else 0.05,
    )


def _clamped_point(day, prediction: float, spread: float) -> ForecastPoint:
    prediction = max(0.0, prediction)
    return ForecastPoint(
        forecast_date=day,
        predicted_quantity=round(prediction, 1),
        lower_bound=round(max(0.0, prediction - spread), 1),
        upper_bound=round(max(0.0, prediction + spread), 1),
    )


def _moving_average_forecast(df: pd.DataFrame, request: ForecastRequest) -> list[ForecastPoint]:
    values = df["y"].astype(float)
    recent_7 = values.tail(7).mean()
    recent_28 = values.tail(min(28, len(values))).mean()
    baseline = 0.65 * recent_7 + 0.35 * recent_28
    spread = max(0.5, values.tail(min(28, len(values))).std(ddof=0) or 0.0)
    last_day = df["ds"].max()
    points: list[ForecastPoint] = []
    for offset in range(1, request.forecast_horizon_days + 1):
        day = last_day + pd.Timedelta(days=offset)
        same_weekday = df.loc[df["ds"].dt.dayofweek == day.dayofweek, "y"].tail(8)
        weekday_value = same_weekday.mean() if not same_weekday.empty else baseline
        points.append(_clamped_point(day.date(), 0.55 * weekday_value + 0.45 * baseline, spread))
    return points


def _prophet_forecast(df: pd.DataFrame, request: ForecastRequest) -> list[ForecastPoint]:
    model = _build_prophet_model(request.category_type)
    model.fit(df)
    result = model.predict(model.make_future_dataframe(periods=request.forecast_horizon_days)).tail(request.forecast_horizon_days)
    return [
        _clamped_point(row["ds"].date(), float(row["yhat"]), max(0.0, float(row["yhat_upper"] - row["yhat"])))
        for _, row in result.iterrows()
    ]


def run_forecast(request: ForecastRequest) -> ForecastResponse:
    df = pd.DataFrame([{"ds": item.sale_date, "y": item.quantity_sold} for item in request.sales_history])
    df["ds"] = pd.to_datetime(df["ds"])
    df = df.groupby("ds", as_index=False)["y"].sum().sort_values("ds")

    model_used = "weighted_moving_average"
    points = _moving_average_forecast(df, request)
    if os.getenv("FORECAST_USE_PROPHET", "false").lower() in {"1", "true", "yes"}:
        try:
            points = _prophet_forecast(df, request)
            model_used = "prophet"
        except Exception:
            pass

    forecasted_demand = round(sum(point.predicted_quantity for point in points), 1)
    suggested_qty = max(0, math.ceil(forecasted_demand + request.safety_stock - request.current_stock))
    if request.max_stock > 0:
        suggested_qty = min(suggested_qty, max(0, request.max_stock - request.current_stock))

    return ForecastResponse(
        product_id=request.product_id,
        forecast=points,
        forecasted_demand=forecasted_demand,
        suggested_reorder_quantity=suggested_qty,
        model_used=model_used,
    )
