# MedDRA Hierarchy Provider — Module Ngoại vi REDCap

Module ngoại vi REDCap cung cấp **mã hóa MedDRA theo phân cấp** với tính năng autocomplete (tự động gợi ý) lọc theo tầng. Khi chọn SOC, trường HLGT chỉ hiển thị các thuật ngữ thuộc SOC đó. Khi chọn HLGT, trường HLT chỉ hiển thị các thuật ngữ con của HLGT đó — cho phép nhập liệu nhanh và chính xác với 5 trường rõ ràng thay vì một ô tìm kiếm chứa hơn 70.000 thuật ngữ.

---

## Tính năng

| Tính năng | Chi tiết |
|---|---|
| **5 trường autocomplete theo tầng** | SOC → HLGT → HLT → PT → LLT |
| **Lọc phân cấp thực sự** | Mỗi tầng chỉ hiển thị các thuật ngữ con của tầng cha đang được chọn |
| **Sử dụng file MedDRA `.asc` nội bộ** | Không cần API bên ngoài hay kết nối internet |
| **Bộ nhớ đệm JSON** | File `.asc` được đọc một lần rồi lưu cache JSON, các lần sau truy xuất rất nhanh |
| **Xóa theo tầng** | Thay đổi trường cha tự động xóa tất cả các trường con phía dưới |
| **Hoạt động trên form nhập liệu và khảo sát** | |
| **Chuẩn Ontology Provider** | Hiển thị trong Online Designer giống BioPortal, LOINC, v.v. |
| **Chỉ hiển thị LLT còn hiệu lực** | Các thuật ngữ LLT đã thu hồi (currency = N) bị loại khỏi kết quả tìm kiếm |

---

## Yêu cầu hệ thống

- REDCap **≥ 10.0.0** với External Module Framework **v12**
- PHP **≥ 7.4**
- File phân phối MedDRA `.asc` *(cấp phép bởi MSSO/ICH — bạn phải có đăng ký MedDRA hợp lệ)*
- Quyền ghi của web server tại thư mục chứa file cache

---

## Cài đặt

### Bước 1 — Tạo gói module

Chạy script build từ thư mục gốc của repository để tạo file ZIP:

```bash
bash build.sh          # tạo dist/meddra_hierarchy_provider_v1.0.0.zip
bash build.sh 2.0.0    # tạo một phiên bản cụ thể
```

### Bước 2 — Triển khai lên REDCap

**Cách A — Tải lên qua Control Center (khuyến nghị):**

1. Vào **Control Center → External Modules → Manage**
2. Nhấn **Upload a package** và chọn file `meddra_hierarchy_provider_v1.0.0.zip`

**Cách B — Sao chép thủ công:**

Giải nén file ZIP và sao chép thư mục vào thư mục modules của REDCap trên máy chủ:

```
/var/www/redcap/modules/meddra_hierarchy_provider_v1.0.0/
```

> Tên thư mục phải đặt đúng định dạng `<tên_module>_v<phiên_bản>` — REDCap lấy số phiên bản từ tên thư mục.

### Bước 3 — Kích hoạt trong Control Center

1. Vào **Control Center → External Modules → Manage**
2. Tìm **MedDRA Hierarchy Provider** và nhấn **Enable**

### Bước 4 — Cấu hình System Settings

Nhấn nút **Configure** bên cạnh module và điền các thông tin sau:

| Cài đặt | Mô tả | Ví dụ |
|---|---|---|
| **MedDRA Data Directory Path** | Đường dẫn tuyệt đối đến thư mục chứa file `.asc` trên máy chủ | `/var/meddra/MedAscii` |
| **Cache File Path** | Đường dẫn file cache JSON. User web server (`www-data`) phải có **quyền ghi** tại đây. | `/var/meddra/meddra_cache.json` |
| **MedDRA Version Label** *(tùy chọn)* | Hiển thị trong Online Designer để phân biệt phiên bản | `27.1` |

**Các file `.asc` bắt buộc** (phải có đủ trong thư mục dữ liệu):

```
soc.asc        hlgt.asc       hlt.asc
soc_hlgt.asc   hlgt_hlt.asc   hlt_pt.asc
pt.asc         llt.asc
```

### Bước 5 — Bật cho dự án của bạn

1. Mở dự án → **External Modules → Manage**
2. Nhấn **Enable** bên cạnh MedDRA Hierarchy Provider

---

## Thiết lập trường trong instrument

### Cách A — Dùng Online Designer

1. Thêm một trường **Text Box**
2. Trong phần **Validation**, chọn **MedDRA Hierarchy** từ dropdown ontology
3. Chọn tầng: SOC, HLGT, HLT, PT hoặc LLT
4. Với mỗi trường con (từ HLGT trở xuống), thêm `@MEDDRA_PARENT=<tên_trường_cha>` vào ô **Field Annotation**

### Cách B — Dùng Data Dictionary CSV

Thêm các dòng sau vào file CSV khi upload. Cột `Choices` và `Field Annotation` là quan trọng nhất:

| Variable / Field Name | Field Type | Choices, Calculations, OR Slider Labels | Field Annotation |
|---|---|---|---|
| `meddra_soc` | text | `MEDDRA_HIER:MEDDRA_SOC` | *(để trống)* |
| `meddra_hlgt` | text | `MEDDRA_HIER:MEDDRA_HLGT` | `@MEDDRA_PARENT=meddra_soc` |
| `meddra_hlt` | text | `MEDDRA_HIER:MEDDRA_HLT` | `@MEDDRA_PARENT=meddra_hlgt` |
| `meddra_pt` | text | `MEDDRA_HIER:MEDDRA_PT` | `@MEDDRA_PARENT=meddra_hlt` |
| `meddra_llt` | text | `MEDDRA_HIER:MEDDRA_LLT` | `@MEDDRA_PARENT=meddra_pt` |

> **Lưu ý:** JavaScript xóa theo tầng sử dụng đúng các tên biến mặc định này. Nếu bạn đặt tên trường khác, tính năng lọc vẫn hoạt động qua annotation `@MEDDRA_PARENT`, nhưng chức năng "xóa tất cả tầng con ngay lập tức" sẽ không kích hoạt. Xem mảng `clearOrder` trong file `MeddraHierarchyProviderModule.php` nếu cần tùy chỉnh.

### Giá trị được lưu trong REDCap

- **Mã số MedDRA** được lưu dưới dạng giá trị trường (ví dụ: `10005329`)
- **Nhãn văn bản** được REDCap cache trong `redcap_web_service_cache` và tự động hiển thị khi xem bản ghi
- Piping `[meddra_soc]` trong email, alert hoặc instrument sẽ hiển thị nhãn đầy đủ

---

## Cơ chế hoạt động (tổng quan kỹ thuật)

```
 Người dùng gõ vào trường autocomplete
          │
          ▼
 JS được inject kiểm tra annotation @MEDDRA_PARENT
          │
          ├─ Trường cha có giá trị? ──► AJAX → search_service.php
          │                             ?category=MEDDRA_HLGT
          │                             &search=cardiac
          │                             &parent_code=10007541   ← mã SOC
          │                                   │
          │                                   ▼
          │                            MeddraHierarchyProviderModule
          │                            ::handleSearchRequest()
          │                                   │
          │                            Tải cache JSON
          │                            Lọc: soc_hlgt[10007541] → [hlgt_codes]
          │                            Tìm kiếm trong ~15 HLGT thuộc SOC đó
          │                                   │
          │                            Trả về: [{code, label}, ...]
          │
          └─ Chưa chọn cha? ──────────► Tìm kiếm gốc của REDCap (toàn bộ danh sách)
```

1. Lần đầu sử dụng, module đọc file `.asc` và ghi ra `meddra_cache.json`
2. Mỗi lần tải form nhập liệu / khảo sát, một đoạn `<script>` nhỏ được inject để chặn jQuery UI Autocomplete trên các trường con
3. Khi người dùng gõ, script đọc giá trị của trường cha và gửi kèm `?parent_code=` đến endpoint tìm kiếm
4. Endpoint chỉ trả về các thuật ngữ con của cha đã chọn
5. Khi trường cha thay đổi, các trường con tự động bị xóa

---

## Xây dựng lại cache

Khi bạn cập nhật file MedDRA `.asc` (ví dụ: nâng cấp phiên bản hàng năm), hãy xóa file cache:

```bash
rm /var/meddra/meddra_cache.json
```

Module sẽ tự động xây dựng lại khi có yêu cầu tìm kiếm tiếp theo.

---

## Xử lý sự cố

| Triệu chứng | Nguyên nhân có thể | Cách khắc phục |
|---|---|---|
| "MedDRA Hierarchy" không xuất hiện trong Online Designer | Module chưa bật ở cấp hệ thống | Control Center → External Modules → Enable |
| Không có thuật ngữ nào hiện ra ở trường SOC | Đường dẫn sai hoặc thiếu file `.asc` | Kiểm tra **MedDRA Data Directory Path** trong system settings; xác nhận file tồn tại trên máy chủ |
| Tìm kiếm không trả về kết quả (mọi tầng) | File cache bị hỏng hoặc rỗng | Xóa file cache để module tự xây dựng lại |
| Trường con hiển thị toàn bộ danh sách thay vì đã lọc | Thiếu hoặc sai annotation `@MEDDRA_PARENT` | Mở trường trong Online Designer và kiểm tra Field Annotation |
| Autocomplete không hoạt động | Lỗi JavaScript | Mở Dev Tools → Console trình duyệt để xem lỗi; nếu cần, tăng delay `1500` ms trong module |
| File cache không được tạo | Web server thiếu quyền ghi | `chown www-data /var/meddra && chmod 755 /var/meddra` |
| LLT hiển thị các thuật ngữ đã thu hồi | Cache cũ được tạo bởi phiên bản module trước khi sửa lỗi | Xóa file cache để buộc xây dựng lại |

---

## Cấu trúc thư mục

```
meddra_hierarchy_provider_v1.0.0/     ← deploy thư mục này lên REDCap
├── config.json                       Metadata EM và định nghĩa settings
├── MeddraHierarchyProviderModule.php  Lớp module chính (OntologyProvider)
├── search_service.php                Endpoint AJAX tìm kiếm (no-auth)
└── README.md                         Hướng dẫn sử dụng (tiếng Anh)
```

---

## Cấu trúc cột file MedDRA `.asc` (tham khảo)

Module đọc 8 file. Bố cục cột (sau khi tách theo ký tự `$`):

| File | [0] | [1] | [2] | Cột đặc biệt |
|---|---|---|---|---|
| `soc.asc` | soc_code | soc_name | soc_abbrev | |
| `hlgt.asc` | hlgt_code | hlgt_name | | |
| `hlt.asc` | hlt_code | hlt_name | | |
| `pt.asc` | pt_code | pt_name | *(null)* | [3] pt_soc_code |
| `llt.asc` | llt_code | llt_name | pt_code | **[8] llt_currency** (Y=còn hiệu lực, N=đã thu hồi) |
| `soc_hlgt.asc` | soc_code | hlgt_code | | *(file mapping)* |
| `hlgt_hlt.asc` | hlgt_code | hlt_code | | *(file mapping)* |
| `hlt_pt.asc` | hlt_code | pt_code | | *(file mapping)* |

---

## Giấy phép

Module này được cung cấp nguyên trạng, không kèm giấy phép cụ thể. **Thuật ngữ MedDRA được cấp phép bởi ICH/MSSO — bạn phải có đăng ký MedDRA hợp lệ để sử dụng file `.asc`.**
