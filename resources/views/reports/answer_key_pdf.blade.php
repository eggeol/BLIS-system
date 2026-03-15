<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { margin: 0 0 8px; font-size: 18px; }
        h2 { margin: 16px 0 6px; font-size: 13px; }
        p.meta { margin: 2px 0; }
        .question-card { border: 1px solid #d1d5db; border-radius: 6px; padding: 8px; margin-bottom: 8px; }
        .muted { color: #6b7280; }
        ul { margin: 4px 0 0 18px; }
        li { margin: 2px 0; }
    </style>
</head>
<body>
    <h1>Answer Key</h1>

    <p class="meta"><strong>Exam:</strong> {{ $exam['title'] ?? 'N/A' }}</p>
    <p class="meta"><strong>Room:</strong> {{ $room['name'] ?? 'N/A' }} ({{ $room['code'] ?? 'N/A' }})</p>
    <p class="meta"><strong>Generated:</strong> {{ optional($generatedAt ?? null)->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s') }}</p>

    @if (empty($answerKey))
        <p class="muted">No answer key is available for this exam.</p>
    @else
        @foreach ($answerKey as $item)
            <div class="question-card">
                <h2>Item {{ $item['item_number'] }} ({{ $item['question_type'] }})</h2>
                <p><strong>Question:</strong> {{ $item['question_text'] }}</p>
                <p><strong>Correct Answer:</strong> {{ $item['correct_answer']['display'] ?? 'N/A' }}</p>

                @if (!empty($item['options']))
                    <p><strong>Options:</strong></p>
                    <ul>
                        @foreach ($item['options'] as $option)
                            <li>
                                {{ $option['label'] }}. {{ $option['text'] }}
                                @if ($option['is_correct'])
                                    <strong>(Correct)</strong>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    @endif
</body>
</html>
