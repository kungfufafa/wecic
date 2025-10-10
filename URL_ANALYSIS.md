# CIC Portal URL Structure Analysis

## Overview
This document analyzes the URL structure and parameter patterns for CIC (Campus Information Center) portal scraping, specifically for class detail pages.

## URL Pattern

### Base Structure
```
https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=download&id={class_id}&dosen_id={lecturer_id}&course_id={course_id}
```

### URL Components
- **Base URL**: `https://my.cic.ac.id/portal/sipandai/`
- **Module**: `mod=sipandai`
- **Action**: `act=download`

### Required Parameters
| Parameter | Type | Range | Required | Description |
|----------|------|-------|----------|-------------|
| `id` | Integer | 1000-9999 | Yes | Unique class identifier |
| `dosen_id` | Integer | 1000-9999 | Yes | Lecturer identifier |
| `course_id` | Integer | 8000-9999 | Yes | Course identifier |

## Sample URLs

### Valid Examples
1. **Class 3142**: `/portal/sipandai/?mod=sipandai&act=download&id=3142&dosen_id=1863&course_id=8861`
2. **Class 1451**: `/portal/sipandai/?mod=sipandai&act=download&id=1451&dosen_id=2744&course_id=8833`

## Parameter Relationships

### Parameter Combinations
- Each unique combination represents a specific class
- Same `id` with different `dosen_id` = different class sections
- Same `course_id` with different `id` = different class meetings

### Usage Guidelines
1. **Primary Identifier**: Use `id` for class identification (primary key)
2. **Lecturer Filtering**: Use `dosen_id` for lecturer-specific filtering
3. **Course Context**: Use `course_id` for course-specific data

## Expected Data Structure

### API Response Format
```json
{
  "status": "success",
  "data": {
    "course_info": {
      "name": "string - Course name",
      "code": "string - Course code",
      "class_name": "string - Class name/section",
      "credits": "string - Number of credits"
    },
    "lecturer_info": {
      "name": "string - Lecturer name",
      "contact": "string - Contact number",
      "email": "string - Email address"
    },
    "schedule_info": {
      "date": "string - Meeting date",
      "start_time": "string - Start time",
      "end_time": "string - End time",
      "room": "string - Classroom/room"
    },
    "attendance_info": {
      "status": "string - Attendance status",
      "time": "string - Check-in time"
    },
    "rps_info": {
      "file_link": "string - RPS file download link",
      "file_text": "string - RPS file button text"
    },
    "assignment_info": {
      "upload_link": "string - Assignment upload link",
      "upload_text": "string - Upload button text",
      "deadline": "string - Assignment deadline"
    },
    "course_materials": [
      {
        "title": "string - Material title",
        "date": "string - Material date",
        "time": "string - Material time",
        "description": "string - Material description",
        "join_link": {
          "href": "string - Join meeting link",
          "text": "string - Join button text"
        }
      }
    ],
    "conformity_info": {
      "description": "string - Conformity feedback"
    },
    "materials": {
      "pdf_links": "array - PDF material links",
      "ppt_links": "array - PowerPoint material links"
    }
  }
}
```

## API Endpoints

### 1. Class Detail by ID
- **URL**: `GET /api/campus/class-detail-by-id?class_id={id}`
- **Purpose**: Get detailed information about a specific class
- **Validation**: Uses `ClassDetailRequest` form request

### 2. Meeting Detail by ID
- **URL**: `GET /api/campus/meeting-detail-by-id?meeting_id={meeting_id}`
- **Purpose**: Get detailed information about a specific meeting
- **Note**: `meeting_id` is extracted from `course_materials[].join_link.href`

## Frontend URL Generation

### 1. Class Detail Page
- **Pattern**: `/classes/{id}`
- **Example**: `/classes/3142`

### 2. Meeting Detail Page
- **Pattern**: `/meetings/{meeting_id}`
- **Example**: `/meetings/28935`

### Meeting ID Extraction
```javascript
// Extract meeting_id from join_link href
const href = "/portal/sipandai/?mod=sipandai&act=discuss&id=28935&courses_id=8861";
const meetingId = href.match(/id=(\d+)/)[1];
// Result: "28935"
```

## Validation Rules

### PHP Form Request Validation
```php
'rules' => [
    'id' => 'required|integer|regex:/^\d{4}$/|between:1000,9999',
    'dosen_id' => 'required|integer|regex:/^\d{4}$/|between:1000,9999',
    'course_id' => 'required|integer|regex:/^\d{4}$/|between:8000,9999',
]
```

### JavaScript Validation (Recommended)
```javascript
const validateParams = (params) => {
    const errors = [];

    // ID validation
    if (!params.id || !/^\d{4}$/.test(params.id)) {
        errors.push('Class ID must be a 4-digit number');
    }
    const id = parseInt(params.id);
    if (id < 1000 || id > 9999) {
        errors.push('Class ID must be between 1000 and 9999');
    }

    // Dosen ID validation
    if (!params.dosen_id || !/^\d{4}$/.test(params.dosen_id)) {
        errors.push('Lecturer ID must be a 4-digit number');
    }
    const dosenId = parseInt(params.dosen_id);
    if (dosenId < 1000 || dosenId > 9999) {
        errors.push('Lecturer ID must be between 1000 and 9999');
    }

    // Course ID validation
    if (!params.course_id || !/^\d{4}$/.test(params.course_id)) {
        errors.push('Course ID must be a 4-digit number');
    }
    const courseId = parseInt(params.course_id);
    if (courseId < 8000 || courseId > 9999) {
        errors.push('Course ID must be between 8000 and 9999');
    }

    return errors;
};
```

## Error Handling

### Validation Errors
- Always provide clear, user-friendly error messages
- Include specific validation rule violations
- Return appropriate HTTP status codes (400 for validation errors)

### Data Missing Errors
- Provide fallback values for optional data fields
- Handle cases where scraping fails gracefully
- Return empty arrays for optional list fields

### Session Errors
- Return 401 status for expired/invalid sessions
- Include clear messages about session requirements

## Security Considerations

### Input Validation
- Always validate input parameters before processing
- Use server-side validation as the primary source of truth
- Sanitize all user input to prevent injection attacks

### Session Management
- Validate campus session before processing requests
- Handle session expiration gracefully
- Log validation attempts for security auditing

## Usage Examples

### 1. Fetching Class Detail
```bash
curl -X GET "http://localhost:8001/api/campus/class-detail-by-id?class_id=3142&dosen_id=1863&course_id=8861" \
  -H "Accept: application/json" \
  -b "laravel_session=your_session_token"
```

### 2. Frontend Implementation
```javascript
async function fetchClassDetail(classId, dosenId, courseId) {
    const response = await fetch(`/api/campus/class-detail-by-id?class_id=${classId}&dosen_id=${dosenId}&course_id=${courseId}`, {
        headers: {
            'Accept': 'application/json'
        }
    });

    const data = await response.json();

    if (data.status === 'success') {
        return data.data;
    } else {
        throw new Error(data.message);
    }
}
```

## Testing

### Test Cases
1. **Valid Parameters**: All parameters within valid ranges
2. **Invalid ID**: Non-numeric or out-of-range ID values
3. **Missing Parameters**: One or more required parameters missing
4. **Invalid Session**: Expired or missing campus session
5. **Edge Cases**: Boundary values for each parameter

### Sample Test Data
```json
{
  "valid": [
    {
      "id": 3142,
      "dosen_id": 1863,
      "course_id": 8861
    },
    {
      "id": 1451,
      "dosen_id": 2744,
      "course_id": 8833
    }
  ],
  "invalid": [
    {
      "id": "abc",
      "dosen_id": 1863,
      "course_id": 8861,
      "error": "ID must be numeric"
    },
    {
      "id": 999,
      "dosen_id": 1863,
      "course_id": 8861,
      "error": "ID must be 4 digits"
    },
    {
      "id": 10000,
      "dosen_id": 1863,
      "course_id": 8861,
      "error": "ID out of range"
    }
  ]
}
```

## Conclusion

The URL structure follows a consistent pattern with three required parameters that work together to uniquely identify and retrieve specific class information from the CIC portal. The validation rules ensure data integrity and prevent invalid requests while maintaining flexibility for legitimate use cases.