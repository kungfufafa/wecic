# 🚀 CIC Campus Portal - Implementation Guide

## 📋 Overview

Aplikasi web Laravel 12 yang berfungsi sebagai **proxy/wrapper** modern untuk sistem kampus CIC (Universitas Catur Insan Cendekia). Aplikasi ini menyediakan dashboard interaktif dengan auto-relogin, scraping data real-time, dan UI yang user-friendly.

## 🎯 Fitur Utama

### ✅ Authentication & Session Management
- **Login Aman**: Enkripsi credentials dengan Laravel Encryption
- **Auto-Relogin**: Mencegah logout otomatis dengan session refresh
- **Session Monitoring**: Real-time session status indicator
- **Error Recovery**: Penanganan graceful error dan auto-recovery

### ✅ Interactive Dashboard
- **Real-time Data**: Data langsung dari portal kampus
- **Responsive Design**: Mobile-friendly dengan sidebar adaptif
- **Loading States**: Indikator loading dan skeleton screens
- **Session Status**: Visual indicator untuk status koneksi

### ✅ Data Extraction
- **Dashboard Data**: User info, notifications, activities
- **Academic Records**: Grades, GPA, transcripts
- **Class Schedule**: Jadwal kuliah dengan multiple format support
- **SIPANDAI Integration**: Data integration dan form handling
- **Financial Data**: Payment history, bills, outstanding balance

## 🛠️ Technical Stack

- **Backend**: Laravel 12 dengan PHP 8.2+
- **Frontend**: Bootstrap 5 + Vanilla JavaScript
- **HTTP Client**: Guzzle + Symfony DomCrawler
- **Session Management**: Redis/File-based dengan encryption
- **Caching**: Multi-level caching strategy
- **Queue System**: Background jobs untuk session refresh

## 📁 Project Structure

```
app/
├── Http/Controllers/API/
│   ├── CampusAuthController.php     # Authentication endpoints
│   └── CampusDataController.php     # Data extraction endpoints
├── Http/Middleware/
│   └── CampusSessionMiddleware.php  # Session validation middleware
├── Services/
│   ├── CampusAuthService.php        # Campus login service
│   ├── CampusSessionManager.php     # Session management
│   ├── CampusScrapingService.php    # Base scraping service
│   └── Extractors/
│       ├── StudentDashboardExtractor.php
│       ├── GradesExtractor.php
│       ├── ScheduleExtractor.php
│       ├── SipandaiExtractor.php
│       └── FinanceExtractor.php
├── Exceptions/
│   ├── CampusException.php
│   ├── CampusConnectionException.php
│   ├── CampusAuthenticationException.php
│   └── CampusScrapingException.php
└── Jobs/
    └── RefreshCampusSessionJob.php   # Background session refresh

resources/
├── views/
│   ├── layouts/
│   │   └── app.blade.php             # Main layout
│   ├── auth/
│   │   └── login.blade.php           # Login page
│   └── dashboard.blade.php           # Main dashboard
├── css/
│   └── app.css                       # Global styles
└── js/
    └── app.js                        # Global JavaScript

routes/
├── web.php                          # Web routes & middleware
└── api.php                          # API endpoints
```

## 🚀 Quick Start

### 1. Environment Setup
```bash
# Install dependencies
composer install
npm install

# Environment configuration
cp .env.example .env
php artisan key:generate

# Configure campus URLs in .env
CAMPUS_BASE_URL="https://my.cic.ac.id"
CAMPUS_LOGIN_URL="https://my.cic.ac.id/portal/mahasiswa/"
CAMPUS_DASHBOARD_URL="https://my.cic.ac.id/portal/mahasiswa/?mod=student&act=dashboard"
```

### 2. Run Development Server
```bash
# Start Laravel server
php artisan serve

# Start Vite for assets (if using)
npm run dev
```

### 3. Access Application
- **URL**: http://localhost:8000
- **Login**: Gunakan credentials kampus CIC Anda
- **Dashboard**: http://localhost:8000/dashboard (setelah login)

## 🔐 API Endpoints

### Authentication
```http
POST /api/campus/login
GET  /api/campus/check-session
POST /api/campus/refresh-session
POST /api/campus/logout
```

### Data Extraction (Protected)
```http
GET /api/campus/dashboard            # Full dashboard data
GET /api/campus/dashboard-overview  # Quick overview
GET /api/campus/grades              # Academic records
GET /api/campus/grades-summary      # Grades summary
GET /api/campus/schedule            # Class schedule
GET /api/campus/schedule-summary    # Schedule overview
GET /api/campus/sipandai            # SIPANDAI data
GET /api/campus/sipandai-overview   # SIPANDAI overview
GET /api/campus/finance             # Financial data
GET /api/campus/finance-summary     # Finance overview
```

## 📊 Data Flow Architecture

```
User → Frontend → API Controller → Service Layer → Campus System
    ↓           ↓              ↓              ↓             ↓
Login Form → AJAX Request → CampusAuthController → CampusAuthService → HTTP Request
    ↓           ↓              ↓              ↓             ↓
Dashboard → API Response → CampusDataController → Extractors → Data Parsing
    ↓           ↓              ↓              ↓             ↓
UI Update → JavaScript → JSON Response → Formatted Data → Campus Response
```

## 🔧 Configuration Options

### Session Management
```env
# Session settings
CAMPUS_AUTO_RELOGIN=true              # Enable auto-relogin
CAMPUS_SESSION_REFRESH_INTERVAL=1200  # Refresh interval (seconds)
CAMPUS_SESSION_PREFIX="cic_session_"   # Session key prefix
```

### Caching Strategy
```env
# Cache TTL settings
CAMPUS_CACHE_PROFILE_TTL=3600    # Profile data (1 hour)
CAMPUS_CACHE_DYNAMIC_TTL=900     # Dynamic data (15 minutes)
CAMPUS_CACHE_HTML_TTL=300        # HTML responses (5 minutes)
CAMPUS_CACHE_FAILED_TTL=60       # Failed requests (1 minute)
```

### Request Settings
```env
# HTTP request configuration
CAMPUS_REQUEST_TIMEOUT=30         # Request timeout (seconds)
CAMPUS_REQUEST_DELAY=1000         # Delay between requests (milliseconds)
```

## 🎨 Frontend Features

### Responsive Design
- **Mobile First**: Sidebar yang dapat di-collapse pada mobile
- **Touch Friendly**: Button dan form yang mudah diakses
- **Adaptive Layout**: Grid system yang menyesuaikan ukuran layar

### User Experience
- **Loading States**: Skeleton screens dan spinners
- **Error Handling**: User-friendly error messages
- **Session Monitoring**: Real-time status indicator
- **Auto-refresh**: Background data updates

### Interactive Elements
- **Sidebar Navigation**: Menu dengan active state indicators
- **Dashboard Cards**: Hover effects dan animations
- **Data Tables**: Sortable dan responsive tables
- **Alert System**: Auto-dismissing notifications

## 🔍 Error Handling & Recovery

### Session Management
- **Auto-relogin**: Automatic session recovery on expiry
- **Graceful Degradation**: Fallback mechanisms for connection issues
- **User Notifications**: Clear error messages dan recovery instructions

### Connection Issues
- **Retry Logic**: Exponential backoff untuk failed requests
- **Circuit Breaker**: Prevent cascade failures
- **Network Detection**: Handle offline scenarios

### Data Validation
- **Response Validation**: Validate campus system responses
- **Data Sanitization**: Clean dan normalize extracted data
- **Fallback Values**: Default values untuk missing data

## 📈 Performance Optimization

### Caching Strategy
- **Multi-level Caching**: Response, data, dan session caching
- **Smart Invalidation**: Targeted cache refreshes
- **Background Refresh**: Pre-load critical data

### Request Management
- **Rate Limiting**: Prevent server overload
- **Request Pooling**: Efficient HTTP connection reuse
- **Background Jobs**: Non-blocking session management

### Frontend Optimization
- **Lazy Loading**: Load data on-demand
- **Code Splitting**: Separate JavaScript modules
- **Asset Optimization**: Minified CSS dan JavaScript

## 🧪 Testing Strategy

### Unit Tests
```php
// Test authentication service
$authService = new CampusAuthService();
$result = $authService->login('username', 'password');

// Test session management
$sessionManager = new CampusSessionManager();
$session = $sessionManager->createSession('username', 'password');
```

### Integration Tests
```php
// Test API endpoints
$response = $this->post('/api/campus/login', [
    'username' => 'test',
    'password' => 'test'
]);
$response->assertStatus(200);
```

### Frontend Tests
```javascript
// Test dashboard loading
describe('Dashboard', () => {
    it('should load dashboard data', async () => {
        const response = await fetch('/api/campus/dashboard');
        expect(response.status).toBe(200);
    });
});
```

## 🚀 Deployment

### Production Setup
```bash
# Optimize application
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set up queues
php artisan queue:work --daemon

# Run scheduler (for session refresh)
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

### Environment Variables
```env
APP_ENV=production
APP_DEBUG=false
LOG_CHANNEL=stack

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE= cic_portal
DB_USERNAME= username
DB_PASSWORD= password

# Cache
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

### Security Considerations
- **HTTPS**: Force HTTPS di production
- **CORS**: Configure CORS untuk API access
- **Rate Limiting**: Implement rate limiting
- **Input Validation**: Validate semua user inputs
- **CSRF Protection**: Enable CSRF protection

## 🔄 Monitoring & Maintenance

### Health Checks
```bash
# Check application health
php artisan up

# Monitor queue jobs
php artisan queue:monitor

# Check session status
php artisan campus:check-sessions
```

### Logging
- **Application Logs**: Monitor application performance
- **Error Tracking**: Track scraping errors
- **Session Logs**: Monitor session management
- **Performance Metrics**: Track response times

### Regular Maintenance
- **Cache Cleanup**: Clear expired cache entries
- **Log Rotation**: Rotate log files
- **Database Optimization**: Optimize database tables
- **Security Updates**: Keep dependencies updated

## 🤝 Contributing

### Code Standards
- **PSR-12**: Follow PHP coding standards
- **ESLint**: Use consistent JavaScript style
- **Documentation**: Comment complex logic
- **Testing**: Write tests for new features

### Development Workflow
1. **Feature Branch**: Create branch for new features
2. **Testing**: Write dan run tests
3. **Code Review**: Peer review changes
4. **Integration**: Test integration with existing code
5. **Deployment**: Deploy to staging then production

## 📞 Support & Troubleshooting

### Common Issues
- **Login Failed**: Check credentials dan campus system status
- **Session Expired**: Check session refresh configuration
- **Data Missing**: Validate campus system accessibility
- **Performance Issues**: Check cache configuration

### Debug Mode
```bash
# Enable debug logging
php artisan log:level=debug

# Check application logs
tail -f storage/logs/laravel.log

# Monitor queue jobs
php artisan queue:failed
```

### Performance Monitoring
- **Response Times**: Monitor API response times
- **Error Rates**: Track error frequencies
- **Cache Hit Rates**: Monitor cache effectiveness
- **Session Health**: Track session lifecycle

---

**Selamat menggunakan CIC Campus Portal!** 🎓

Aplikasi ini dirancang untuk memberikan pengalaman yang seamless dalam mengakses data kampus dengan session management yang robust dan UI yang modern.