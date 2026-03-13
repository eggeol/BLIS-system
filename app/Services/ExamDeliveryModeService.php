<?php

namespace App\Services;

use App\Models\Exam;

class ExamDeliveryModeService
{
    public function normalize(?string $mode): string
    {
        return Exam::DELIVERY_MODE_OPEN_NAVIGATION;
    }
}
