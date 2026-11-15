<?php

namespace App\Support;

use App\Filament\Forms\AvatarUpload;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Support\HtmlString;

final class UserAvatar
{
    public static function upload(string $directory): AvatarUpload
    {
        return AvatarUpload::make('avatar_path')
            ->label(__('Profile picture'))
            ->disk('public')
            ->directory($directory)
            ->visibility('public')
            ->maxSize(2048);
    }

    public static function html(?User $user, bool $withName = true, string $size = 'md'): HtmlString
    {
        $sizeClass = $size === 'sm' ? ' wp-user-avatar-sm' : '';

        if ($user === null) {
            return new HtmlString(
                '<span class="wp-user-with-avatar'.$sizeClass.'"><span class="wp-user-avatar wp-user-avatar-fallback" aria-hidden="true">?</span></span>'
            );
        }

        $name = e($user->displayName());
        $url = $user->avatarUrl();
        $initials = e($user->avatarInitials());

        $image = $url
            ? '<img src="'.e($url).'" alt="" class="wp-user-avatar-img" loading="lazy" onerror="this.remove()" />'
            : '';

        $accessibleName = $withName ? ' aria-hidden="true"' : ' role="img" aria-label="'.$name.'"';

        $avatar = '<span class="wp-user-avatar wp-user-avatar-fallback"'.$accessibleName.'>'.$initials.$image.'</span>';

        if (! $withName) {
            return new HtmlString('<span class="wp-user-with-avatar'.$sizeClass.'">'.$avatar.'</span>');
        }

        $profile = '';

        if (auth()->user()?->can('view', $user)) {
            $label = e(__('View profile for :name', ['name' => $user->displayName()]));
            $profile = '<a class="wp-user-profile-link" href="'.e(UserResource::getUrl('view', ['record' => $user])).'" aria-label="'.$label.'" title="'.$label.'" aria-haspopup="dialog" x-data x-on:click.prevent.stop="$dispatch(\'show-user-profile\', { userId: '.(int) $user->id.' })">'
                .'<span class="wp-user-profile-eye" aria-hidden="true"></span><span class="sr-only">'.$label.'</span></a>';
        }

        return new HtmlString(
            '<span class="wp-user-with-avatar'.$sizeClass.'">'.$avatar.'<span class="wp-user-avatar-name">'.$name.'</span>'.$profile.'</span>'
        );
    }
}
