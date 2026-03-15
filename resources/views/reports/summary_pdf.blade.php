<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { margin: 0 0 8px; font-size: 18px; }
        h2 { margin: 18px 0 8px; font-size: 14px; }
        h3 { margin: 14px 0 6px; font-size: 12px; }
        p.meta { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #d1d5db; padding: 6px; vertical-align: top; }
        th { background: #f3f4f6; text-align: left; }
        .muted { color: #6b7280; }
        .pill { display: inline-block; border: 1px solid #d1d5db; border-radius: 999px; padding: 2px 8px; margin-right: 6px; }
    </style>
</head>
<body>
    <h1>Results Summary</h1>

    <p class="meta"><strong>Exam:</strong> {{ $session['exam']['title'] ?? 'N/A' }}</p>
    <p class="meta"><strong>Room:</strong> {{ $session['room']['name'] ?? 'N/A' }} ({{ $session['room']['code'] ?? 'N/A' }})</p>
    <p class="meta"><strong>Generated:</strong> {{ optional($session['generated_at'] ?? null)->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s') }}</p>

    <h2>Class Statistics</h2>
    <table>
        <tr>
            <th>Students</th>
            <th>Started</th>
            <th>Submitted</th>
            <th>Average Score %</th>
            <th>Highest %</th>
            <th>Lowest %</th>
        </tr>
        <tr>
            <td>{{ $session['summary']['students_total'] ?? 0 }}</td>
            <td>{{ $session['summary']['attempts_started'] ?? 0 }}</td>
            <td>{{ $session['summary']['attempts_submitted'] ?? 0 }}</td>
            <td>{{ is_null($session['summary']['average_score_percent'] ?? null) ? 'N/A' : number_format((float) $session['summary']['average_score_percent'], 2) }}</td>
            <td>{{ is_null($session['summary']['highest_score_percent'] ?? null) ? 'N/A' : number_format((float) $session['summary']['highest_score_percent'], 2) }}</td>
            <td>{{ is_null($session['summary']['lowest_score_percent'] ?? null) ? 'N/A' : number_format((float) $session['summary']['lowest_score_percent'], 2) }}</td>
        </tr>
    </table>

    <h2>Item Analysis</h2>
    <table>
        <tr>
            <th>Item</th>
            <th>Question</th>
            <th>Started</th>
            <th>Answered</th>
            <th>Correct</th>
            <th>% Answered</th>
            <th>% Correct</th>
        </tr>
        @foreach (($session['item_summary'] ?? []) as $item)
            <tr>
                <td>{{ $item['item_number'] }}</td>
                <td>{{ $item['question_text'] ?? 'N/A' }}</td>
                <td>{{ $item['started_count'] }}</td>
                <td>{{ $item['answered_count'] }}</td>
                <td>{{ $item['correct_count'] }}</td>
                <td>{{ number_format((float) ($item['answered_percent'] ?? 0), 2) }}</td>
                <td>{{ is_null($item['correct_percent'] ?? null) ? 'N/A' : number_format((float) $item['correct_percent'], 2) }}</td>
            </tr>
        @endforeach
    </table>

    <h2>Hardest and Easiest Items</h2>
    <p>
        <span class="pill"><strong>Hardest:</strong>
            @if (empty($session['hardest_items'] ?? []))
                N/A
            @else
                @foreach ($session['hardest_items'] as $item)
                    Item {{ $item['item_number'] }} ({{ is_null($item['correct_percent']) ? 'N/A' : number_format((float) $item['correct_percent'], 2) }}%)@if (!$loop->last), @endif
                @endforeach
            @endif
        </span>
    </p>
    <p>
        <span class="pill"><strong>Easiest:</strong>
            @if (empty($session['easiest_items'] ?? []))
                N/A
            @else
                @foreach ($session['easiest_items'] as $item)
                    Item {{ $item['item_number'] }} ({{ is_null($item['correct_percent']) ? 'N/A' : number_format((float) $item['correct_percent'], 2) }}%)@if (!$loop->last), @endif
                @endforeach
            @endif
        </span>
    </p>

    <h2>Short-Answer Text Dump</h2>
    @if (empty($session['short_answer_dump'] ?? []))
        <p class="muted">No short-answer responses found for this session.</p>
    @else
        @foreach ($session['short_answer_dump'] as $item)
            <h3>Item {{ $item['item_number'] }}: {{ $item['question_text'] }}</h3>
            <table>
                <tr>
                    <th>Student</th>
                    <th>Student ID</th>
                    <th>Response</th>
                    <th>Status</th>
                </tr>
                @foreach (($item['responses'] ?? []) as $response)
                    <tr>
                        <td>{{ $response['student_name'] ?? 'N/A' }}</td>
                        <td>{{ $response['student_id'] ?? 'N/A' }}</td>
                        <td>{{ $response['answer_text'] ?? '' }}</td>
                        <td>
                            @if (($response['is_correct'] ?? null) === true)
                                Correct
                            @elseif (($response['is_correct'] ?? null) === false)
                                Wrong
                            @else
                                Pending Review
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        @endforeach
    @endif
</body>
</html>
