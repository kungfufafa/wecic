# CIC Portal Scraping API - Debug Guide

## 📋 Overview

Guide lengkap untuk debugging dan testing hasil scraping data dari CIC Portal. Ada beberapa cara untuk memverifikasi data yang di-scraping benar atau tidak.

## 🛠️ Debug Tools yang Tersedia

### 1. Interactive API Documentation (Scramble)
**URL**: `http://localhost:8000/docs/api`

Fitur:
- 🌐 **Interactive UI**: Bisa test API langsung dari browser
- 📝 **Auto-generated Documentation**: Dokumentasi otomatis dari annotations
- 🧪 **Try It Feature**: Test API dengan parameter custom
- 🎨 **Modern UI**: Interface yang user-friendly

### 2. Debug API Endpoints
Khusus untuk debugging dengan response yang lebih detail:

#### Session Status
```bash
GET http://localhost:8000/api/debug/session-status
```
Response mencakup:
- Session validity
- Session token preview
- Full session data
- Campus session validation

#### Test Dashboard Scraping
```bash
GET http://localhost:8000/api/debug/dashboard
```
Response mencakup:
- Raw dashboard data
- Execution time
- Data sources used
- Debug information

#### Test Profile Scraping
```bash
GET http://localhost:8000/api/debug/profile
```
Response mencakup:
- Raw profile data
- Personal, contact, education data
- Extraction details

#### Test Classes Scraping
```bash
GET http://localhost:8000/api/debug/classes
```
Response mencakup:
- Raw classes data
- Total classes count
- Student information
- Class details with join links

#### Test Class Detail Scraping
```bash
GET http://localhost:8000/api/debug/class-detail?class_id=3142&dosen_id=1863&course_id=8861
```
Parameters:
- `class_id`: ID kelas (4 digit)
- `dosen_id`: ID dosen (4 digit)
- `course_id`: ID mata kuliah (4 digit)

### 3. Postman Collection
**File**: `CIC_API_POSTMAN_COLLECTION.json`

Cara import:
1. Buka Postman
2. Click "Import"
3. Pilih file `CIC_API_POSTMAN_COLLECTION.json`
4. Collection akan terimport dengan semua endpoints

**Variables otomatis**:
- `sessionId`: Auto-set setelah login
- `baseUrl`: Base URL server
- `userInfo`: User information

### 4. Laravel Artisan Commands
```bash
# Test scraping dengan analisis URL
php artisan scrape:test --analyze

# Test specific URLs
php artisan scrape:test
```

## 🔍 Step-by-Step Debugging Process

### Step 1: Cek Server Status
Pastikan server berjalan:
```bash
php artisan serve --host=0.0.0.0 --port=8000
```

### Step 2: Test Session Status
```bash
curl http://localhost:8000/api/debug/session-status
```
Response harus menunjukkan session status dan debug info.

### Step 3: Login dan Test Scraping

#### Via Browser:
1. Buka `http://localhost:8000/docs/api`
2. Expand "Authentication" → "Login"
3. Click "Try it"
4. Masukkan credentials:
   ```json
   {
     "username": "20251030030",
     "password": "Kanjutz123@"
   }
   ```
5. Click "Execute"

#### Via curl:
```bash
curl -X POST http://localhost:8000/api/campus/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -c cookies.txt \
  -d '{"username":"20251030030","password":"Kanjutz123@"}'
```

### Step 4: Test Scraping Endpoints

#### Test Dashboard:
```bash
curl -b cookies.txt http://localhost:8000/api/debug/dashboard | jq .
```

#### Test Profile:
```bash
curl -b cookies.txt http://localhost:8000/api/debug/profile | jq .
```

#### Test Classes:
```bash
curl -b cookies.txt http://localhost:8000/api/debug/classes | jq .
```

#### Test Class Detail:
```bash
curl -b cookies.txt "http://localhost:8000/api/debug/class-detail?class_id=3142&dosen_id=1863&course_id=8861" | jq .
```

## 📊 Response Structure Analysis

### Debug Response Format:
```json
{
  "status": "success|error",
  "message": "Description of the operation",
  "raw_data": {
    // Actual scraped data
  },
  "debug_info": {
    "session_valid": true,
    "scraping_time": "2025-10-11T01:30:34.000Z",
    "execution_time_ms": 1234.56,
    "data_sources": ["dashboard_page", "profile_data"],
    "session_token_preview": "abc123...",
    "error_type": "ExceptionClass",
    "error_details": "Stack trace..."
  }
}
```

### Normal API Response Format:
```json
{
  "status": "success|error",
  "message": "Description of the operation",
  "data": {
    // Cleaned/processed data
  },
  "user_info": {
    // Basic user info
  },
  "meta": {
    "timestamp": "2025-10-11T01:30:34.000Z",
    "campus_session_valid": true
  }
}
```

## 🚨 Common Issues & Solutions

### Issue 1: Session Expired
**Symptoms**:
- Response: `"status": "error", "message": "No active session found"`

**Solution**:
```bash
# Check session status
curl http://localhost:8000/api/debug/session-status

# Re-login
curl -X POST http://localhost:8000/api/campus/login \
  -H "Content-Type: application/json" \
  -d '{"username":"20251030030","password":"Kanjutz123@"}'
```

### Issue 2: Scraping Failed
**Symptoms**:
- Response: `"status": "error", "message": "Failed to extract..."`

**Debug Steps**:
1. Cek debug endpoint untuk error details
2. Verify URL parameters valid
3. Check if campus portal is accessible
4. Look for scraping logs in `storage/logs/laravel.log`

### Issue 3: Invalid Parameters
**Symptoms**:
- Response: `422 Unprocessable Entity`

**Solution**:
- Validate parameter formats
- Class ID: 4 digits (1000-9999)
- Lecturer ID: 4 digits (1000-9999)
- Course ID: 4 digits (8000-9999)

### Issue 4: CORS Issues
**Symptoms**:
- Browser console shows CORS errors
- Network requests blocked

**Solution**:
- Ensure using `web` middleware for API routes
- Check CORS configuration in `config/cors.php`

## 🔧 Advanced Debugging

### 1. Enable Detailed Logging
```bash
# Enable debug mode
APP_ENV=local
APP_DEBUG=true

# Check logs
tail -f storage/logs/laravel.log | grep -i scraping
```

### 2. Inspect Extracted HTML
Untuk melihat HTML yang di-scrape:

```php
// In any extractor method
$html = $this->get($url);
Log::info('Raw HTML', ['html' => $html]);
```

### 3. Test Individual Selectors
```bash
# Test specific CSS selectors
php artisan tinker
>>> $crawler = new Symfony\Component\DomCrawler\Crawler($html);
>>> $crawler->filter('.card')->count();
>>> $crawler->filter('table tr')->first()->text();
```

### 4. Performance Analysis
```bash
# Measure execution time
php artisan scrape:test --analyze

# Check memory usage
php artisan tinker
>>> memory_get_usage(true);
>>> memory_get_peak_usage(true);
```

## 📱 Testing on Mobile

Untuk testing API di mobile device:

1. **Server Access**:
   ```bash
   # Get your IP address
   ipconfig getifaddr en0  # macOS
   ip addr show           # Linux

   # Start server with accessible host
   php artisan serve --host=0.0.0.0 --port=8000
   ```

2. **Update Base URL**:
   - Di Postman: Update `baseUrl` variable
   - Di browser: Gunakan IP address instead of localhost

## 📈 Monitoring & Analytics

### Session Health Check
```bash
# Create health check endpoint
curl http://localhost:8000/api/debug/session-status
```

### Performance Metrics
Debug endpoints menyediakan:
- Execution time in milliseconds
- Data sources used
- Cache hit/miss status
- Error rates

## 🎯 Best Practices

### 1. Use Debug Endpoints for Development
- Debug endpoints provide more detailed information
- Better for troubleshooting extraction issues
- Include raw data and metadata

### 2. Use Normal Endpoints for Production
- Cleaner responses
- Better performance
- Consistent API contract

### 3. Monitor Session Health
- Always check session validity before scraping
- Implement auto-refresh for long-running operations
- Handle session expiry gracefully

### 4. Validate Parameters
- Use regex validation for IDs
- Check parameter ranges
- Provide helpful error messages

### 5. Log Everything
- Log scraping operations
- Track errors and exceptions
- Monitor performance metrics

## 📚 Quick Reference

### URL Patterns:
- **Login**: `POST /api/campus/login`
- **Session Check**: `GET /api/campus/check-session`
- **Debug Session**: `GET /api/debug/session-status`
- **Dashboard**: `GET /api/debug/dashboard`
- **Profile**: `GET /api/debug/profile`
- **Classes**: `GET /api/debug/classes`
- **Class Detail**: `GET /api/debug/class-detail?class_id=X&dosen_id=Y&course_id=Z`

### Response Codes:
- `200`: Success
- `401`: Authentication required
- `422`: Validation error
- `500`: Server error

### Error Debugging:
1. Check session status first
2. Verify parameters
3. Look at debug info in response
4. Check Laravel logs
5. Test with debug endpoints

---

**🎉 Selamat debugging!** Dengan tools ini, kamu bisa dengan mudah memverifikasi bahwa data yang di-scraping sudah benar dan lengkap.