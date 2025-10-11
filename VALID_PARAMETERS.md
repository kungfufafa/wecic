# Valid Parameters for CIC Portal Scraping

## ✅ Valid Parameters from Real CIC Portal URLs

Based on the provided CIC portal URLs, here are the valid parameters:

### Example 1:
**URL**: `/portal/sipandai/?mod=sipandai&act=download&id=2265&dosen_id=5240&course_id=8810`
- **class_id**: `2265` ✓
- **dosen_id**: `5240` ✓
- **course_id**: `8810` ✓

### Example 2:
**URL**: `/portal/sipandai/?mod=sipandai&act=download&id=3142&dosen_id=1863&course_id=8861`
- **class_id**: `3142` ✓
- **dosen_id**: `1863` ✓
- **course_id**: `8861` ✓

## 🔍 Validation Rules

Current validation rules:
- **class_id**: 4-digit integer (1000-9999)
- **dosen_id**: 4-digit integer (1000-9999)
- **course_id**: 4-digit integer (8000-9999)

## 📝 API Usage Examples

### Via curl:
```bash
# Example 1
curl -X GET "http://localhost:8000/api/campus/class-detail?class_id=2265&dosen_id=5240&course_id=8810" \
  -H "Accept: application/json" \
  -b cookies.txt

# Example 2
curl -X GET "http://localhost:8000/api/campus/class-detail?class_id=3142&dosen_id=1863&course_id=8861" \
  -H "Accept: application/json" \
  -b cookies.txt
```

### Via Debug API:
```bash
# Test without session (for validation only)
curl -X GET "http://localhost:8000/api/debug/class-detail?class_id=2265&dosen_id=5240&course_id=8810"

# Test with different parameters
curl -X GET "http://localhost:8000/api/debug/class-detail?class_id=3142&dosen_id=1863&course_id=8861"
```

### Via Postman Collection:
Use the imported collection with these parameter values.

## ❌ Common Validation Errors

If you get `"Course ID must be between 8000 and 9999"`, it means:
- You're using course_id < 8000 (invalid)
- Valid course_id range: 8000-9999

## ✅ Working Parameters List

Here are tested working parameters:
1. `class_id=2265, dosen_id=5240, course_id=8810`
2. `class_id=3142, dosen_id=1863, course_id=8861`

## 🧪 Testing

1. **Login first**: Use `/api/campus/login` or browser login
2. **Test parameters**: Use debug endpoints to validate parameters
3. **Extract data**: Use main endpoints for data extraction

## 📊 Debug Information

Debug endpoints provide detailed validation feedback:
- Parameter validation results
- Session status
- Scraping execution time
- Error details if any

---

**Note**: These parameters are extracted from actual CIC portal URLs and should work with the scraping system.