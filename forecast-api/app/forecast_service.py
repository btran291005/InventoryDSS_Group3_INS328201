# File: app/forecast_service.py
# Purpose: Logic thuần túy chạy Prophet - tách khỏi main.py (routing) để dễ
# unit test riêng model logic mà không cần khởi động FastAPI server.
#
# Related: FR-SYS-03, FR-MGR-08
#
# Lưu ý quan trọng về category_type: Prophet hỗ trợ "seasonality_mode" và có
# thể thêm regressor riêng, nhưng ở mức capstone này ta chỉ điều chỉnh
# THAM SỐ MÙA VỤ đơn giản theo category_type (không train riêng biệt model
# cho từng loại) - đủ để thể hiện "category-aware forecasting" mà không
# over-engineer trong 5 tuần.

import pandas as pd
from prophet import Prophet

from app.schemas import ForecastRequest, ForecastPoint, ForecastResponse


def _build_prophet_model(category_type: str | None) -> Prophet:
    """Chọn cấu hình Prophet phù hợp theo category_type.

    - Fresh_Food (VD: Gimbap, đồ ăn chế biến tại chỗ): nhu cầu biến động
      mạnh theo ngày trong tuần (cuối tuần bán chạy hơn) -> bật
      weekly_seasonality, KHÔNG bật yearly (dữ liệu quá ngắn để học pattern
      theo năm, ép model học sẽ overfit).
    - Imported_Korean (hạn dùng dài, lead-time dài): nhu cầu ổn định hơn,
      ít biến động ngắn hạn -> seasonality_mode='additive' (mặc định), tắt
      bớt độ nhạy weekly để tránh dự báo dao động giả tạo.
    - FMCG / None (mặc định): cấu hình trung tính, dùng chung.
    """
    if category_type == "Fresh_Food":
        return Prophet(
            daily_seasonality=False,
            weekly_seasonality=True,
            yearly_seasonality=False,
            seasonality_mode="multiplicative",
            changepoint_prior_scale=0.1,  # nhạy hơn với thay đổi xu hướng gần đây
        )

    if category_type == "Imported_Korean":
        return Prophet(
            daily_seasonality=False,
            weekly_seasonality=False,  # ít biến động theo ngày trong tuần
            yearly_seasonality=False,
            seasonality_mode="additive",
            changepoint_prior_scale=0.03,  # ổn định hơn, ít nhạy với biến động ngắn hạn
        )

    # FMCG hoặc không xác định category_type
    return Prophet(
        daily_seasonality=False,
        weekly_seasonality=True,
        yearly_seasonality=False,
        seasonality_mode="additive",
        changepoint_prior_scale=0.05,
    )


def run_forecast(request: ForecastRequest) -> ForecastResponse:
    """Chạy Prophet trên sales_history đã nhận, trả về dự báo N ngày tới.

    Raises:
        ValueError: nếu dữ liệu đầu vào không đủ để Prophet fit (Prophet tự
            raise lỗi nội bộ trong 1 số trường hợp, ta bắt lại và raise
            ValueError để main.py trả 422 thay vì 500 - phân biệt rõ "lỗi do
            dữ liệu đầu vào" và "lỗi hệ thống".
    """
    df = pd.DataFrame(
        [
            {"ds": point.sale_date, "y": point.quantity_sold}
            for point in request.sales_history
        ]
    )

    # Prophet yêu cầu cột ds là datetime, không phải date object thuần
    df["ds"] = pd.to_datetime(df["ds"])

    # Gộp các dòng trùng ngày (nếu PHP lỡ gửi 2 dòng cùng ngày do lỗi group-by)
    df = df.groupby("ds", as_index=False)["y"].sum()

    try:
        model = _build_prophet_model(request.category_type)
        model.fit(df)
    except Exception as e:
        raise ValueError(f"Prophet không thể fit dữ liệu đầu vào: {e}") from e

    future = model.make_future_dataframe(periods=request.forecast_horizon_days)
    forecast_df = model.predict(future)

    # Chỉ lấy N ngày tương lai (bỏ phần fit lại lịch sử Prophet trả kèm)
    future_only = forecast_df.tail(request.forecast_horizon_days)

    forecast_points = [
        ForecastPoint(
            forecast_date=row["ds"].date(),
            # BR-03 tinh thần áp dụng cả ở đây: không trả số âm dù Prophet
            # có thể dự báo ra giá trị âm về mặt toán học (đặc biệt với
            # additive seasonality) - làm tròn về 0 cho hợp lý nghiệp vụ.
            predicted_quantity=max(0.0, round(row["yhat"], 1)),
            lower_bound=max(0.0, round(row["yhat_lower"], 1)),
            upper_bound=max(0.0, round(row["yhat_upper"], 1)),
        )
        for _, row in future_only.iterrows()
    ]

    suggested_qty = round(sum(p.predicted_quantity for p in forecast_points), 1)

    return ForecastResponse(
        product_id=request.product_id,
        forecast=forecast_points,
        suggested_reorder_quantity=suggested_qty,
        model_used="prophet",
    )