# Demand Forecast API

From this folder, create a virtual environment, install dependencies, then start the service:

```powershell
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
uvicorn app.main:app --host 127.0.0.1 --port 8000
```

Set `FORECAST_API_KEY` in `.env` and configure the same key in `api_configs`.
The PHP app accepts either `http://127.0.0.1:8000` or the full
`http://127.0.0.1:8000/forecast` endpoint. The response includes a 7-day
forecast and a stock-aware suggested order quantity. The default weighted
moving-average model starts quickly and is reliable for sparse demo data. Set
`FORECAST_USE_PROPHET=true` only when you want to opt into the slower Prophet
model; the API falls back to the weighted model if it cannot fit the data.
