<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Interview;


Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// ✅ ✅ ✅ أضف هذه القناة للمقابلة
Broadcast::channel('interview.{id}', function ($user, $id) {
    $interview = Interview::find($id);

    // فقط صاحب المقابلة يمكنه الاشتراك
    return $user && $interview && $user->id === $interview->user_id;
});
