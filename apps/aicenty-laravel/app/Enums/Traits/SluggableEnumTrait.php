<?php

declare(strict_types=1);

namespace App\Enums\Traits;

use App\Helpers\Classes\EntityRemover;
use Illuminate\Support\Facades\Log;
use RuntimeException;

trait SluggableEnumTrait
{
    public function slug(): string
    {
        return str_replace('.', '__', $this->value);
    }

    public static function fromSlug(string $value): self
    {
        $self = self::tryFrom(str_replace('__', '.', $value));

        if ($self === null) {
            EntityRemover::removeEntity($value);
        }

        if ($self === null) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = $backtrace[1] ?? [];
            $error = [
                'slug'                       => $value,
                'fromSlug_called_from'       => $caller['function'] ?? null,
                'fromSlug_called_from_class' => $caller['class'] ?? null,
                'fromSlug_called_in'         => $caller['file'] ?? null,
                'fromSlug_called_line'       => $caller['line'] ?? null,
            ];

            Log::error('-------------------------------------------------\n');
            Log::error('fromSlug called with invalid slug: ' . json_encode($error, JSON_THROW_ON_ERROR));
            Log::error('-------------------------------------------------\n');

            throw new RuntimeException('Invalid enum slug: ' . $value . '. Please refresh the page');
        }

        return $self;
    }
}
