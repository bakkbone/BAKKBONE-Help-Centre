<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Client;

use Bakkbone\HelpCentre\Controllers\Controller;
use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Settings;
use Bakkbone\HelpCentre\Services\Auth;
use Bakkbone\HelpCentre\Services\Users;

final class ProfileController extends Controller
{
    public function edit(): void
    {
        $user = Auth::requireUser();
        $this->view('client/profile', ['title' => __('My profile'), 'user' => $user, 'org' => Users::org($user['org_id'] ? (int)$user['org_id'] : null)]);
    }

    public function update(): void
    {
        $user = Auth::requireUser();
        $name = Request::str('name');
        if ($name === '') {
            $this->failWith(__('Please enter your name.'), '/profile');
        }
        $update = ['name' => mb_substr($name, 0, 190), 'phone' => Request::str('phone') ?: null, 'updated_at' => DB::now()];

        $new = (string)Request::post('password', '');
        if ($new !== '' && Settings::bool('client_password_login')) {
            if ($user['password_hash'] && !Auth::verifyPassword('users', $user, (string)Request::post('current_password', ''))) {
                $this->failWith(__('Your current password is incorrect.'), '/profile');
            }
            if ($err = Auth::passwordError($new, (string)Request::post('password_confirm', ''))) {
                $this->failWith($err, '/profile');
            }
            $update['password_hash'] = Auth::hash($new);
        }
        App::db()->update('users', $update, 'id = ?', [$user['id']]);
        $this->flash('success', __('Your profile has been saved.'));
        $this->redirect('/profile');
    }
}
