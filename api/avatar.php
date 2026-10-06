<?php
// A gallery profile picture (users.avatar = 'up/<file>'), for any signed-in user — they show next to names
// everywhere in the app. Presets live in assets/pfp and are linked directly instead.
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/uploads.php';

api_user();
$avatar = user_avatar(int_param('u'));
if (!$avatar || !str_starts_with($avatar, 'up/')) {
    fail('No photo.', 404);
}
serve_upload('avatars', substr($avatar, 3));
