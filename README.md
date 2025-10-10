# LARAVEL 12 CAMPUS PROXY APPLICATION IMPLEMENTATION

## 🎯 CORE PROJECT OBJECTIVES

### Primary Goal
Buat aplikasi Laravel yang berfungsi sebagai **proxy/wrapper** untuk aplikasi kampus PHP fullstack yang jelek, dengan fokus:
- **Session Management yang Robust** - Tidak pernah logout/blank page lagi
- **Modern REST API** - Transform HTML responses jadi JSON API yang bersih
- **Better UX/UI** - Interface yang user-friendly dan responsive
- **Error Handling yang Proper** - No more blank pages atau cryptic errors

## 📋 TECHNICAL IMPLEMENTATION RULES

### Rule 1: Project Structure & Setup
```
WAJIB:
- Laravel 12 terbaru dengan PHP 8.2+
- Struktur folder terorganisir dengan Services, Repositories, Middleware pattern
- Environment variables untuk semua config aplikasi kampus
- Logging comprehensive untuk debugging session issues

LARANGAN:
- Jangan hardcode URL/credentials aplikasi kampus
- Jangan skip error handling di setiap HTTP request
- Jangan lupakan rate limiting untuk prevent overload kampus server
```

### Rule 2: Session Management Architecture
```
IMPLEMENTASI WAJIB:
1. CampusSessionManager class untuk maintain session kampus
2. Auto-relogin mechanism saat detect session expired
3. Cookie persistence dengan Redis/file storage
4. Session timeout detection dengan multiple indicators
5. Fallback mechanism untuk connection issues

PATTERN:
- Detect blank page = session expired → auto relogin
- Detect redirect to /login = session expired → refresh session  
- Maintain session background job untuk prevent expiry
- Store encrypted credentials untuk auto-relogin
```

### Rule 3: Web Scraping Implementation
```
LIBRARY STACK:
- Symfony DomCrawler untuk HTML parsing (WAJIB)
- Guzzle HTTP Client dengan persistent cookies
- Laravel HTTP Client dengan proxy support
- Laravel Cache untuk response caching

SCRAPING RULES:
- SELALU handle dynamic content loading
- SELALU validate response sebelum parsing
- SELALU implement retry mechanism (max 3x)
- SELALU respect rate limiting (delay antar request)
- SELALU log failed requests untuk debugging
```

### Rule 4: Data Extraction & Transformation
```
EXTRACTION PATTERN:
1. Buat DataExtractor classes untuk setiap halaman kampus
2. Gunakan CSS selectors yang robust (multiple fallback selectors)
3. Validate extracted data sebelum return
4. Transform HTML tables jadi array/collection yang terstruktur
5. Handle missing/malformed data gracefully

CONTOH STRUKTUR:
- StudentDashboardExtractor
- GradesExtractor
- ScheduleExtractor
- SipandaiExtractor
- FinanceExtractor
```

### Rule 5: API Design & Routes
```
REST API ENDPOINTS (WAJIB):
GET /api/auth/login        - Login ke aplikasi kampus
GET /api/dashboard         - Dashboard mahasiswa
GET /api/grades            - Nilai/transkrip
GET /api/schedule          - Jadwal kuliah
GET /api/sipandai          - Data sipandai
GET /api/finances          - Data keuangan
POST /api/auth/refresh     - Refresh session
POST /api/auth/logout      - Logout

RESPONSE FORMAT (WAJIB):
{
  "status": "success|error",
  "message": "Human readable message",
  "data": {...},
  "meta": {
    "timestamp": "ISO datetime",
    "campus_session_valid": true|false
  }
}
```

### Rule 6: Error Handling Strategy
```
ERROR HANDLING LEVELS:
1. HTTP Client Level - Connection timeouts, network errors
2. Session Level - Authentication failures, session expiry
3. Scraping Level - HTML parsing errors, missing elements
4. Data Level - Invalid/missing data validation
5. Application Level - Laravel exceptions, logic errors

RESPONSE UNTUK SETIAP ERROR:
- User-friendly error messages (Indonesian)
- Proper HTTP status codes
- Actionable error details
- Automatic retry suggestions where applicable
```

### Rule 7: Security & Authentication
```
SECURITY REQUIREMENTS:
- Store kampus credentials dengan Laravel encryption
- Implement CSRF protection untuk internal API
- Rate limiting untuk prevent abuse
- Input validation dan sanitization
- Secure session storage (Redis recommended)
- IP whitelisting untuk production (optional)

AUTHENTICATION FLOW:
User login → Validate credentials → Login ke kampus → Store session → Return JWT/API token
```

### Rule 8: Performance & Caching
```
CACHING STRATEGI:
- Session cookies: Cache selama session valid
- Static data (profile): Cache 1 jam
- Dynamic data (grades): Cache 15 menit  
- Scraped HTML: Cache 5 menit
- Failed requests: Cache 1 menit (untuk retry logic)

BACKGROUND JOBS:
- Session refresh job (setiap 20 menit)
- Data pre-fetch job (cache warm-up)
- Health check job (monitor kampus app availability)
```

### Rule 9: Frontend Integration Guidelines
```
FRONTEND SPECS:
- Support untuk REST API consumption
- Modern UI framework (React/Vue/Inertia recommended)
- Mobile-responsive design
- Real-time session status indicator
- Offline support dengan cached data
- Progressive loading untuk better UX

API CLIENT REQUIREMENTS:
- Automatic token refresh
- Request retry dengan exponential backoff
- Loading states untuk setiap action
- Error boundary untuk graceful error handling
```

### Rule 10: Testing & Quality Assurance
```
TESTING REQUIREMENTS:
- Unit tests untuk semua Service classes
- Feature tests untuk API endpoints
- Integration tests untuk scraping logic
- Mock kampus responses untuk testing
- Load testing untuk performance validation

KUALITAS CODE:
- PHP 8.2 strict types
- PSR-12 coding standards
- Laravel 12 best practices
- Comprehensive error logging
- Code documentation yang clear
```

## 🚫 CRITICAL DON'Ts

### Authentication & Session
- JANGAN store plain text passwords
- JANGAN skip session validation sebelum scraping
- JANGAN assume session masih valid tanpa check
- JANGAN ignore blank page responses

### Scraping & HTTP Requests
- JANGAN scrape tanpa User-Agent header
- JANGAN ignore rate limiting (minimum 1 detik delay)
- JANGAN skip error handling di HTTP requests
- JANGAN hardcode CSS selectors tanpa fallback

### Data & API
- JANGAN return raw HTML ke frontend
- JANGAN skip data validation setelah scraping
- JANGAN expose internal error details ke client
- JANGAN cache sensitive data tanpa encryption

## 🎯 SUCCESS CRITERIA

✅ User tidak pernah logout unexpected lagi  
✅ No blank pages atau cryptic errors  
✅ Loading time < 3 detik  
✅ 99.9% uptime untuk internal API  
✅ Full test coverage untuk critical paths

## 🛠️ IMPLEMENTATION CHECKLIST

### Phase 1: Core Setup
- Laravel 12 installation + required packages
- DB schema & session storage config
- Basic auth system & env setup
- Logging config

### Phase 2: Campus Integration
- Login mechanism analysis & implementation
- Session manager & cookie handling
- Auto-relogin logic
- Connection error handling

### Phase 3: Data Scraping
- DomCrawler setup
- DataExtractor classes per module
- Response transformation
- Cache & background jobs

### Phase 4: API Development
- Define routes & controllers
- Response format & error middleware
- Rate limiting
- API documentation

### Phase 5: Frontend Integration
- Frontend framework & API client
- UI/UX components & responsive design
- Loading & error boundary

### Phase 6: Testing & Deployment
- Unit, feature, integration tests
- Load & security testing
- Production deployment & monitoring


---

**Use Laravel 12** sebagai core framework, ikuti semua rules di atas untuk memastikan robustness, scalability, dan user-friendly experience.