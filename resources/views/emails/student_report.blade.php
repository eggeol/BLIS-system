<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5; }
        .container { max-width: 640px; margin: 0 auto; padding: 20px; }
        .meta { margin: 4px 0; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; margin-top: 14px; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <div class="container">
        <p>Hello {{ $report['student']['name'] ?? 'Student' }},</p>

        <p>
            Your individual exam report is attached as a PDF file.
            Please keep it for your records.
        </p>

        <div class="card">
            <p class="meta"><strong>Exam:</strong> {{ $report['exam']['title'] ?? 'N/A' }}</p>
            <p class="meta"><strong>Room:</strong> {{ $report['room']['name'] ?? 'N/A' }} ({{ $report['room']['code'] ?? 'N/A' }})</p>
            <p class="meta"><strong>Attempt Status:</strong> {{ $report['attempt']['status'] ?? 'N/A' }}</p>
            <p class="meta"><strong>Score:</strong> {{ is_null($report['attempt']['score_percent'] ?? null) ? 'N/A' : number_format((float) $report['attempt']['score_percent'], 2) . '%' }}</p>
        </div>

        <p class="muted">
            Sent by {{ $teacher['name'] ?? 'Faculty' }} via BLIS System.
        </p>
    </div>
</body>
</html>

