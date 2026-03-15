<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { margin: 0 0 8px; font-size: 18px; }
        h2 { margin: 16px 0 8px; font-size: 14px; }
        p.meta { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #d1d5db; padding: 6px; vertical-align: top; }
        th { background: #f3f4f6; text-align: left; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <h1>Individual Student Report</h1>

    <p class="meta"><strong>Student:</strong> {{ $report['student']['name'] ?? 'N/A' }}</p>
    <p class="meta"><strong>Student ID:</strong> {{ $report['student']['student_id'] ?? 'N/A' }}</p>
    <p class="meta"><strong>Email:</strong> {{ $report['student']['email'] ?? 'N/A' }}</p>

    <p class="meta"><strong>Exam:</strong> {{ $report['exam']['title'] ?? 'N/A' }}</p>
    <p class="meta"><strong>Room:</strong> {{ $report['room']['name'] ?? 'N/A' }} ({{ $report['room']['code'] ?? 'N/A' }})</p>
    <p class="meta"><strong>Attempt ID:</strong> {{ $report['attempt']['id'] ?? 'N/A' }}</p>
    <p class="meta"><strong>Attempt Status:</strong> {{ $report['attempt']['status'] ?? 'N/A' }}</p>
    <p class="meta"><strong>Started At:</strong> {{ optional($report['attempt']['started_at'] ?? null)->format('Y-m-d H:i:s') ?? 'N/A' }}</p>
    <p class="meta"><strong>Submitted At:</strong> {{ optional($report['attempt']['submitted_at'] ?? null)->format('Y-m-d H:i:s') ?? 'N/A' }}</p>

    <h2>Score Summary</h2>
    <table>
        <tr>
            <th>Total Items</th>
            <th>Answered</th>
            <th>Correct</th>
            <th>Score %</th>
        </tr>
        <tr>
            <td>{{ $report['attempt']['total_items'] ?? 0 }}</td>
            <td>{{ $report['attempt']['answered_count'] ?? 0 }}</td>
            <td>{{ $report['attempt']['correct_answers'] ?? 0 }}</td>
            <td>{{ is_null($report['attempt']['score_percent'] ?? null) ? 'N/A' : number_format((float) $report['attempt']['score_percent'], 2) }}</td>
        </tr>
    </table>

    <h2>Per-Question Breakdown</h2>
    @if (empty($report['items'] ?? []))
        <p class="muted">No item data available for this attempt.</p>
    @else
        <table>
            <tr>
                <th>Item</th>
                <th>Question</th>
                <th>Selected Answer</th>
                <th>Correct Answer</th>
                <th>Status</th>
            </tr>
            @foreach ($report['items'] as $item)
                <tr>
                    <td>{{ $item['item_number'] }}</td>
                    <td>{{ $item['question_text'] ?? 'N/A' }}</td>
                    <td>{{ $item['selected_answer'] ?? 'No Answer' }}</td>
                    <td>{{ $item['correct_answer']['display'] ?? 'N/A' }}</td>
                    <td>{{ $item['status_label'] ?? 'N/A' }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
