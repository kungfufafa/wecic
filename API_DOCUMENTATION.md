# CIC Portal API Documentation

## Overview

API documentation for CIC (Cibodas Inti College) Portal scraping system. This API provides access to student academic data through real-time web scraping from the official CIC portal.

**Base URL:** `http://localhost:8000/api/campus`
**Authentication:** Required (Session-based)
**Content-Type:** `application/json`

## Authentication Flow

### 1. Web Authentication
```http
POST /login
Content-Type: application/json
Accept: application/json

{
  "username": "20251030030",
  "password": "Kanjutz123@"
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Login berhasil",
  "data": {
    "session_token": "NelUR5QMbw6d51Qi4HQIg8SLrvgsK9iWKgWtiDkd1gKfY7WlcjJDLOdWEYMQvCBa",
    "user_info": [],
    "expires_at": "2025-10-10T18:48:21.158943Z"
  },
  "meta": {
    "timestamp": "2025-10-10T18:28:21.197134Z",
    "campus_session_valid": true
  }
}
```

### 2. Session Management
- Session token stored in Laravel session
- Automatic session validation for API requests
- Session expires based on CIC portal timeout

## API Endpoints

### 1. Dashboard API

**Endpoint:** `GET /api/campus/dashboard`
**Description:** Get comprehensive dashboard overview with student statistics

**Response:**
```json
{
  "status": "success",
  "message": "Dashboard data retrieved successfully",
  "data": {
    "user_info": {
      "name": "John Doe",
      "student_id": "20251030030",
      "email": "john.doe@student.cic.ac.id"
    },
    "dashboard_overview": {
      "total_courses": "6",
      "gpa": "3.75",
      "total_credits": "20",
      "outstanding_payments": "Rp 0"
    },
    "recent_activity": [
      {
        "title": "Assignment Submitted",
        "date": "2025-10-11",
        "description": "Web Programming Assignment"
      }
    ],
    "classes_summary": {
      "total_classes": 6,
      "active_semester": "Ganjil 2025/2026",
      "recent_classes": [
        {
          "name": "Web Programming",
          "schedule": "Senin, 08:00-10:00"
        }
      ]
    }
  },
  "meta": {
    "timestamp": "2025-10-11T01:30:34.000Z",
    "campus_session_valid": true
  }
}
```

### 2. Profile API

**Endpoint:** `GET /api/campus/profile`
**Description:** Get detailed student profile information

**Response:**
```json
{
  "status": "success",
  "message": "Profile data retrieved successfully",
  "user_info": {
    "name": "John Doe",
    "student_id": "20251030030",
    "email": "john.doe@student.cic.ac.id"
  },
  "profile": {
    "personal_data": {
      "name": "John Doe",
      "student_id": "20251030030",
      "birth_place": "Jakarta",
      "birth_date": "2005-03-15",
      "gender": "Laki-laki",
      "religion": "Islam",
      "nationality": "Indonesia"
    },
    "contact_data": {
      "email": "john.doe@student.cic.ac.id",
      "phone": "+62 812-3456-7890",
      "address": "Jl. Example No. 123, Jakarta",
      "emergency_contact": "+62 813-9876-5432"
    },
    "education_data": {
      "program": "S1 Teknik Informatika",
      "faculty": "Fakultas Teknik",
      "entry_year": "2025",
      "status": "Aktif"
    },
    "photo_url": "https://my.cic.ac.id/photos/john_doe.jpg",
    "extracted_at": "2025-10-11T01:28:30.000Z"
  },
  "meta": {
    "timestamp": "2025-10-11T01:28:30.000Z",
    "campus_session_valid": true
  }
}
```

### 3. Classes API

**Endpoint:** `GET /api/campus/classes`
**Description:** Get list of all enrolled classes for current semester

**Response:**
```json
{
  "status": "success",
  "message": "Classes data retrieved successfully",
  "user_info": {
    "name": "John Doe",
    "student_id": "20251030030"
  },
  "classes": [
    {
      "id": "3142",
      "name": "Pemrograman Web",
      "lecturer": "Dr. Budi Santoso, S.Kom., M.Kom.",
      "schedule": "Senin, 08:00-10:00",
      "room": "Lab Komputer A",
      "credits": "3",
      "semester": "Ganjil 2025/2026",
      "status": "Aktif"
    },
    {
      "id": "1451",
      "name": "Basis Data",
      "lecturer: "Prof. Anita Wijaya, S.Kom., M.T.",
      "schedule": "Rabu, 10:00-12:00",
      "room": "Ruang 301",
      "credits": "3",
      "semester": "Ganjil 2025/2026",
      "status": "Aktif"
    }
  ],
  "meta": {
    "timestamp": "2025-10-11T01:28:31.000Z",
    "campus_session_valid": true
  }
}
```

### 4. Class Detail API

**Endpoint:** `GET /api/campus/class-detail-by-id`
**Description:** Get detailed information for specific class including attendance and materials

**Parameters:**
- `class_id` (required, integer, 4 digits, 1000-9999)
- `dosen_id` (required, integer, 4 digits, 1000-9999)
- `course_id` (required, integer, 4 digits, 8000-9999)

**Example Request:**
```http
GET /api/campus/class-detail-by-id?class_id=3142&dosen_id=1863&course_id=8861
```

**Response:**
```json
{
  "status": "success",
  "message": "Class detail retrieved successfully",
  "class_detail": {
    "request_url": "/portal/sipandai/?mod=sipandai&act=download&id=3142&dosen_id=1863&course_id=8861",
    "content_type": "class_detail",
    "course_info": {
      "name": "Pemrograman Web",
      "code": "IF603",
      "credits": 3,
      "semester": "Ganjil 2025/2026"
    },
    "class_info": {
      "class_id": "3142",
      "session": "Reguler",
      "quota": 30,
      "enrolled": 28
    },
    "lecturer_info": {
      "name": "Dr. Budi Santoso, S.Kom., M.Kom.",
      "nidn": "1863",
      "email": "budi.santoso@cic.ac.id"
    },
    "schedule_info": {
      "day": "Senin",
      "time": "08:00-10:00",
      "room": "Lab Komputer A",
      "building": "Gedung Teknik"
    },
    "attendance_info": {
      "total_meetings": 14,
      "attended": 12,
      "absent": 2,
      "percentage": "85.7%"
    },
    "assignment_history": [
      {
        "title": "Tugas 1 - HTML & CSS",
        "due_date": "2025-10-15",
        "status": "Submitted",
        "score": "A"
      }
    ],
    "course_materials": [
      {
        "week": 1,
        "topic": "Introduction to Web Development",
        "material_url": "https://my.cic.ac.id/materials/week1.pdf"
      }
    ],
    "file_info": {
      "syllabus_url": "https://my.cic.ac.id/files/syllabus_3142.pdf",
      "schedule_url": "https://my.cic.ac.id/files/schedule_3142.pdf"
    },
    "students_list": [
      {
        "nim": "20251030030",
        "name": "John Doe",
        "attendance_status": "Present"
      }
    ],
    "extracted_at": "2025-10-11T01:28:33.000Z"
  },
  "meta": {
    "timestamp": "2025-10-11T01:28:33.000Z",
    "campus_session_valid": true
  }
}
```

### 5. Meeting Detail API

**Endpoint:** `GET /api/campus/meeting-detail-by-id`
**Description:** Get detailed information for specific class meeting

**Parameters:**
- `meeting_id` (required, integer)

**Example Request:**
```http
GET /api/campus/meeting-detail-by-id?meeting_id=28935
```

**Response:**
```json
{
  "status": "success",
  "message": "Meeting detail retrieved successfully",
  "meeting_detail": {
    "meeting_id": "28935",
    "meeting_info": {
      "session_number": 5,
      "date": "2025-10-14",
      "time": "08:00-10:00",
      "topic": "JavaScript Fundamentals",
      "room": "Lab Komputer A"
    },
    "course_info": {
      "name": "Pemrograman Web",
      "code": "IF603"
    },
    "lecturer_info": {
      "name": "Dr. Budi Santoso, S.Kom., M.Kom."
    },
    "attendance_info": {
      "total_students": 28,
      "present": 26,
      "absent": 2,
      "attendance_list": [
        {
          "nim": "20251030030",
          "name": "John Doe",
          "status": "Present",
          "check_in_time": "07:58"
        }
      ]
    },
    "meeting_materials": {
      "slides_url": "https://my.cic.ac.id/materials/js_fundamentals.pdf",
      "exercise_url": "https://my.cic.ac.id/exercises/exercise5.pdf"
    },
    "notes": "Discussed variables, functions, and DOM manipulation",
    "extracted_at": "2025-10-11T01:28:33.000Z"
  },
  "meta": {
    "timestamp": "2025-10-11T01:28:33.000Z",
    "campus_session_valid": true
  }
}
```

## Error Responses

### Authentication Errors
```json
{
  "status": "error",
  "message": "No active campus session found"
}
```
**HTTP Status:** 401

### Validation Errors
```json
{
  "status": "error",
  "message": "Missing required parameter: class_id",
  "errors": {
    "class_id": ["The class id field is required."]
  }
}
```
**HTTP Status:** 400

### Scraping Errors
```json
{
  "status": "error",
  "message": "Failed to extract class data: Unable to access class page",
  "meta": {
    "timestamp": "2025-10-11T01:30:00.000Z",
    "campus_session_valid": false
  }
}
```
**HTTP Status:** 500

## URL Parameter Validation

### Class Detail Parameters
- `class_id`: 4-digit integer (1000-9999) with regex validation
- `dosen_id`: 4-digit integer (1000-9999) with regex validation
- `course_id`: 4-digit integer (8000-9999) with regex validation

### Meeting Detail Parameters
- `meeting_id`: Integer, any valid meeting ID

## Rate Limiting & Performance

- **Request Timeout:** 30 seconds per request
- **Connection Timeout:** 10 seconds
- **Cache Duration:** 5 minutes for repeated requests
- **Concurrent Requests:** Limited to prevent server overload

## Data Sources

All data is scraped in real-time from:

- **Dashboard:** `https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=dashboardmahasiswa`
- **Profile:** `https://my.cic.ac.id/portal/mahasiswa/?mod=student&act=profile`
- **Classes:** `https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=dashboardmahasiswa`
- **Class Details:** `https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=download&id={class_id}&dosen_id={dosen_id}&course_id={course_id}`
- **Meeting Details:** `https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=discuss&id={meeting_id}&courses_id={course_id}`

## Usage Examples

### JavaScript/Fetch API Example
```javascript
// Login first
const loginResponse = await fetch('/login', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  },
  body: JSON.stringify({
    username: '20251030030',
    password: 'Kanjutz123@'
  })
});

// Get dashboard data
const dashboardResponse = await fetch('/api/campus/dashboard');
const dashboardData = await dashboardResponse.json();

console.log('Dashboard Data:', dashboardData.data.dashboard_overview);
```

### PHP/Guzzle Example
```php
$client = new GuzzleHttp\Client([
  'base_uri' => 'http://localhost:8000',
  'cookies' => true
]);

// Login
$response = $client->post('/login', [
  'json' => [
    'username' => '20251030030',
    'password' => 'Kanjutz123@'
  ],
  'headers' => [
    'Accept' => 'application/json'
  ]
]);

// Get profile data
$response = $client->get('/api/campus/profile');
$profileData = json_decode($response->getBody(), true);

echo "Student Name: " . $profileData['profile']['personal_data']['name'];
```

## Technical Implementation

### Architecture
- **Framework:** Laravel 12
- **HTTP Client:** Guzzle
- **HTML Parser:** Symfony DomCrawler
- **Session Management:** Laravel Session + CIC Portal Session
- **Caching:** Laravel Cache (5 minutes)

### Security Features
- CSRF protection for web routes
- Session-based authentication
- Input validation with Laravel Form Requests
- Error logging and monitoring

### Monitoring
All API requests are logged with:
- Request timestamp
- Session validity status
- Response time
- Error details (if any)

---

**Last Updated:** 2025-10-11
**Version:** 1.0
**Contact:** Development Team