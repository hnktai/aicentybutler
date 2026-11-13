<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Models\User;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    public function loginIdentifier(): string
    {
        return trim((string) $this->input('email'));
    }

    public function loginUser(): ?User
    {
        $identifier = $this->loginIdentifier();

        if ($identifier === '') {
            return null;
        }

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return User::query()->where('email', $identifier)->first();
        }

        $phoneVariants = $this->phoneLookupVariants($identifier);
        $normalizedVariants = array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => preg_replace('/\D+/', '', $value) ?? '',
            $phoneVariants
        ))));

        return User::query()
            ->where(function ($query) use ($phoneVariants, $normalizedVariants): void {
                $query->whereIn('phone', $phoneVariants);

                foreach ($normalizedVariants as $variant) {
                    $query->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '') = ?",
                        [$variant]
                    );
                }
            })
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function phoneLookupVariants(string $identifier): array
    {
        $variants = [$identifier];
        $digits = preg_replace('/\D+/', '', $identifier) ?? '';

        if ($digits !== '') {
            $variants[] = $digits;

            if (str_starts_with($digits, '84') && strlen($digits) > 2) {
                $variants[] = '0' . substr($digits, 2);
            }

            if (str_starts_with($digits, '0') && strlen($digits) > 1) {
                $variants[] = '84' . substr($digits, 1);
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $user = $this->loginUser();

        if (! $user || ! Auth::attempt([
            'email'    => $user->email,
            'password' => $this->input('password'),
        ], $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->loginIdentifier()) . '|' . $this->ip());
    }
}
