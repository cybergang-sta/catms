<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Core\Exception\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Service\AccountService;

/**
 * The signed-in person's own profile.
 */
final class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        return Response::success($this->accounts()->profile($this->identity($request)));
    }

    public function update(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'first_name' => 'nullable|string|max:80',
            'last_name'  => 'nullable|string|max:80',
            'phone'      => 'nullable|string|max:30',
        ]);

        return Response::success($this->accounts()->updateProfile($this->identity($request), $input));
    }

    public function updatePassword(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'current_password'      => 'required|string|max:200',
            'password'              => 'required|string|max:200',
            'password_confirmation' => 'required|string|max:200',
        ]);
        if ($input['password'] !== $input['password_confirmation']) {
            throw ValidationException::field('password_confirmation', 'The password confirmation does not match.');
        }

        $this->accounts()->changePassword(
            $this->identity($request),
            (string) $input['current_password'],
            (string) $input['password'],
            $request->ip(),
        );

        return Response::noContent();
    }

    private function accounts(): AccountService
    {
        return $this->service(AccountService::class);
    }
}
