# File: app/schemas.py
# Purpose: Định nghĩa request/response schema cho endpoint /forecast, dùng
# Pydantic để FastAPI tự validate input (nếu PHP gửi thiếu field hay sai kiểu,
# FastAPI tự trả 422 kèm chi tiết lỗi - PHP-side (IntegrationService.php) chỉ
# cần check response HTTP status, không cần tự validate lại structure).
#
# Related: FR-SYS-03 (gọi API dự báo nhu cầu), FR-MGR-08 (nhận gợi ý từ API)

from datetime import date
from typing import Optional
from pydantic import BaseModel, Field, field_validator


class SalesHistoryPoint(BaseModel):
    """1 điểm dữ liệu lịch sử bán hàng - tương ứng 1 dòng trong sales_transactions
    đã group theo ngày ở phía PHP trước khi gửi sang đây."""

    sale_date: date = Field(..., description="Ngày bán (YYYY-MM-DD)")
    quantity_sold: float = Field(..., ge=0, description="Tổng số lượng bán trong ngày đó")


class ForecastRequest(BaseModel):
    """Request body cho POST /forecast.

    product_id chỉ dùng để trả lại trong response (traceability) - model
    không tự query DB, PHP phải tự gửi kèm toàn bộ sales_history cần thiết.
    Đây là quyết định thiết kế quan trọng: Python service KHÔNG kết nối
    trực tiếp vào MySQL của hệ thống chính, tránh 2 service phải đồng bộ
    connection string/credentials - PHP luôn là nguồn dữ liệu duy nhất.
    """

    product_id: int = Field(..., description="ID sản phẩm, dùng để trace lại kết quả")
    category_type: Optional[str] = Field(
        None, description="FMCG | Fresh_Food | Imported_Korean - dùng để chọn tham số mùa vụ phù hợp"
    )
    sales_history: list[SalesHistoryPoint] = Field(
        ..., min_length=1, description="Lịch sử bán hàng theo ngày, càng dài càng chính xác"
    )
    forecast_horizon_days: int = Field(
        default=7, ge=1, le=30, description="Số ngày muốn dự báo tới (mặc định 7, tối đa 30)"
    )

    @field_validator("sales_history")
    @classmethod
    def must_have_enough_history(cls, v: list[SalesHistoryPoint]) -> list[SalesHistoryPoint]:
        # Prophet cần tối thiểu 2 điểm để fit, nhưng dưới 14 điểm thì kết quả
        # gần như vô nghĩa (không đủ để bắt weekly seasonality) - chặn sớm ở
        # đây để trả lỗi rõ ràng thay vì để Prophet fit ra kết quả sai lệch
        # mà PHP không biết để cảnh báo người dùng.
        if len(v) < 14:
            raise ValueError(
                f"Cần tối thiểu 14 ngày lịch sử bán hàng để dự báo có ý nghĩa, hiện chỉ có {len(v)} ngày."
            )
        return v


class ForecastPoint(BaseModel):
    forecast_date: date
    predicted_quantity: float = Field(..., description="Số lượng dự báo (đã làm tròn, không âm)")
    lower_bound: float = Field(..., description="Cận dưới khoảng tin cậy 80%")
    upper_bound: float = Field(..., description="Cận trên khoảng tin cậy 80%")


class ForecastResponse(BaseModel):
    product_id: int
    forecast: list[ForecastPoint]
    suggested_reorder_quantity: float = Field(
        ..., description="Tổng dự báo nhu cầu trong forecast_horizon_days - dùng làm gợi ý số lượng đặt hàng"
    )
    model_used: str = Field(default="prophet", description="Luôn 'prophet' nếu thành công - PHP dùng field này để phân biệt với response fallback")


class ErrorResponse(BaseModel):
    detail: str