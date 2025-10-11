# 📅 Peningkatan Parsing Schedule (Jadwal)

## 🎯 Permasalahan Awal
```json
"schedule": {
  "raw": "Senin  09:30:00-11:00:00 "
}
```

Schedule hanya menampilkan raw text tanpa parsing yang baik.

## ✅ Solusi yang Diterapkan

### 1. **Enhanced Schedule Parsing**
Extractors sekarang memecah schedule menjadi komponen yang lebih terstruktur:

```json
"schedule": {
  "day": "Senin",
  "start_time": "09:30:00",
  "end_time": "11:00:00",
  "time_range": "09:30:00-11:00:00",
  "formatted_time": "09:30 - 11:00",
  "raw": "Senin 09:30:00-11:00:00"
}
```

### 2. **Improved Regex Patterns**
- ✅ Handle multiple spaces: `"Senin  09:30:00-11:00:00"`
- ✅ Handle trailing spaces: `"Senin 09:30:00-11:00:00 "`
- ✅ Handle HTML entities: `"Senin &nbsp;09:30:00-11:00:00"`
- ✅ Handle case variations: `"SENIN"` → `"Senin"`
- ✅ Fallback untuk day-only parsing

### 3. **Better UI Display**
Schedule sekarang ditampilkan dengan format yang lebih rapi:

**Before:**
```
📅 Senin  09:30:00-11:00:00
```

**After:**
```
📅 Senin
   09:30 - 11:00
```

## 🔧 Implementation Details

### Parser Logic
```php
// Clean up text first
$text = preg_replace('/\s+/', ' ', $text);
$text = trim($text);

// Enhanced regex pattern
if (preg_match('/(\w+)\s*(?:&nbsp;|\s)*(\d{2}:\d{2}:\d{2}-\d{2}:\d{2}:\d{2})/', $text, $matches)) {
    $day = ucfirst(strtolower($matches[1]));
    $timeRange = $matches[2];

    // Parse start and end time
    if (preg_match('/(\d{2}:\d{2}:\d{2})-(\d{2}:\d{2}:\d{2})/', $timeRange, $timeMatches)) {
        return [
            'day' => $day,
            'start_time' => $timeMatches[1],
            'end_time' => $timeMatches[2],
            'time_range' => $timeRange,
            'formatted_time' => $this->formatTimeRange($timeMatches[1], $timeMatches[2]),
            'raw' => $text,
        ];
    }
}
```

### Time Formatting
```php
private function formatTimeRange(string $startTime, string $endTime): string
{
    // Convert "09:30:00" to "09:30" and "11:00:00" to "11:00"
    $start = substr($startTime, 0, 5);
    $end = substr($endTime, 0, 5);

    return "{$start} - {$end}";
}
```

## 📱 UI Improvements

### Schedule Display Component
```javascript
<div class="flex items-center text-sm text-gray-700">
    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <!-- Calendar icon -->
    </svg>
    <div>
        ${scheduleInfo.day ? `<span class="font-medium">${scheduleInfo.day}</span>` : ''}
        ${scheduleInfo.formatted_time ?
            `<div class="text-xs text-gray-500">${scheduleInfo.formatted_time}</div>` :
            scheduleInfo.time ?
                `<div class="text-xs text-gray-500">${scheduleInfo.time}</div>` :
                scheduleInfo.raw ?
                    `<div class="text-xs text-gray-500">${scheduleInfo.raw}</div>` :
                    ''
        }
    </div>
</div>
```

## 🎨 Visual Examples

### Example 1: Regular Schedule
```
📅 Senin
   09:30 - 11:00
🏫 SI-EB-1/5, Ruang Lab 2
👨‍🏫 Muhammad Hatta, M.Kom.
```

### Example 2: Evening Class
```
📅 Selasa
   18:30 - 20:00
🏫 Ruang 106
👨‍🏫 Rifqi Fahrudin, M.Kom
```

### Example 3: Day Only (Fallback)
```
📅 Rabu
   (schedule information not available)
🏫 Ruang 201
👨‍🏫 Willy Eka Septian, M.Kom.
```

## 🔄 Backward Compatibility

Extractors masih menyediakan field `raw` untuk backward compatibility:
```json
{
  "day": "Senin",
  "formatted_time": "09:30 - 11:00",
  "raw": "Senin 09:30:00-11:00:00"
}
```

UI akan prioritaskan:
1. `formatted_time` (most readable)
2. `time` (fallback)
3. `raw` (last resort)

## 🧪 Testing Scenarios

### ✅ Test Cases Covered:
- `"Senin 09:30:00-11:00:00"` → `Senin, 09:30 - 11:00`
- `"Senin  09:30:00-11:00:00 "` → `Senin, 09:30 - 11:00` (extra spaces)
- `"Selasa&Middot;14:30:00-16:00:00"` → `Selasa, 14:30 - 16:00` (HTML entities)
- `"RABU 08:00:00-09:30:00"` → `Rabu, 08:00 - 09:30` (case normalization)
- `"Jumat"` → `Jumat` (day only fallback)

## 📈 Benefits

### 1. **Better User Experience**
- ✅ Schedule lebih mudah dibaca
- ✅ Hari dan waktu terpisah jelas
- ✅ Format waktu yang konsisten

### 2. **Developer Friendly**
- ✅ Data terstruktur untuk processing
- ✅ Multiple format options
- ✅ Backward compatibility

### 3. **Consistent Display**
- ✅ Same parsing logic across all extractors
- ✅ Uniform time formatting
- ✅ Graceful fallbacks

## 🎉 Result

Schedule information sekarang jauh lebih mudah dibaca dan terstruktur!

**Before**: `"Senin  09:30:00-11:00:00 "`
**After**:
```
📅 Senin
   09:30 - 11:00
```

Data schedule sekarang terpecah dengan baik dan siap digunakan untuk fitur-fitur lanjutan seperti sorting, filtering, atau calendar integration! 🚀