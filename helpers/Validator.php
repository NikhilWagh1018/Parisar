<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/Validator.php
//  Chainable validation helper.
//  Usage:
//    $v = Validator::make($_POST)
//           ->required('session_id', 'segment_id')
//           ->integer('session_id', 'segment_id')
//           ->min('session_id', 1)
//           ->min('segment_id', 1)
//           ->required('start_landmark', 'end_landmark', 'gps_start', 'gps_end');
//
//    if ($v->fails()) {
//        http_response_code(422);
//        echo json_encode(['success' => false, 'error' => $v->firstError()]);
//        exit;
//    }
// ═══════════════════════════════════════════════════════════════

class Validator
{
    /** @var array<string,mixed> */
    private array $data;

    /** @var string[] */
    private array $errors = [];

    // ── Factory ───────────────────────────────────────────────

    /**
     * @param array<string,mixed> $data  Typically $_POST or a parsed JSON body.
     */
    public static function make(array $data): self
    {
        $v       = new self();
        $v->data = $data;
        return $v;
    }

    // ── Rules (all return $this for chaining) ─────────────────

    /**
     * Fields must be present and non-empty after trimming.
     */
    public function required(string ...$fields): self
    {
        foreach ($fields as $field) {
            $val = $this->data[$field] ?? null;
            if ($val === null || trim((string)$val) === '') {
                $this->errors[] = "Required field '{$field}' is missing.";
            }
        }
        return $this;
    }

    /**
     * Fields, when present, must be numeric integers.
     */
    public function integer(string ...$fields): self
    {
        foreach ($fields as $field) {
            if (!isset($this->data[$field])) {
                continue;
            }
            if (filter_var($this->data[$field], FILTER_VALIDATE_INT) === false) {
                $this->errors[] = "Field '{$field}' must be an integer.";
            }
        }
        return $this;
    }

    /**
     * Fields, when present, must be numeric (int or float).
     */
    public function numeric(string ...$fields): self
    {
        foreach ($fields as $field) {
            if (!isset($this->data[$field])) {
                continue;
            }
            if (!is_numeric($this->data[$field])) {
                $this->errors[] = "Field '{$field}' must be numeric.";
            }
        }
        return $this;
    }

    /**
     * Field value must be >= $minimum.
     */
    public function min(string $field, int|float $minimum): self
    {
        if (!isset($this->data[$field])) {
            return $this;
        }
        if ((float)$this->data[$field] < $minimum) {
            $this->errors[] = "Field '{$field}' must be at least {$minimum}.";
        }
        return $this;
    }

    /**
     * Field value must be <= $maximum.
     */
    public function max(string $field, int|float $maximum): self
    {
        if (!isset($this->data[$field])) {
            return $this;
        }
        if ((float)$this->data[$field] > $maximum) {
            $this->errors[] = "Field '{$field}' must be at most {$maximum}.";
        }
        return $this;
    }

    /**
     * Field value, when present, must be in the allowed list.
     *
     * @param string[]|int[] $allowed
     */
    public function in(string $field, array $allowed): self
    {
        if (!isset($this->data[$field])) {
            return $this;
        }
        if (!in_array($this->data[$field], $allowed, true)) {
            $list = implode(', ', $allowed);
            $this->errors[] = "Field '{$field}' must be one of: {$list}.";
        }
        return $this;
    }

    /**
     * Field value, when present, must match a regex pattern.
     */
    public function regex(string $field, string $pattern): self
    {
        if (!isset($this->data[$field])) {
            return $this;
        }
        if (!preg_match($pattern, (string)$this->data[$field])) {
            $this->errors[] = "Field '{$field}' has an invalid format.";
        }
        return $this;
    }

    /**
     * String field length must not exceed $max characters.
     */
    public function maxLength(string $field, int $max): self
    {
        if (!isset($this->data[$field])) {
            return $this;
        }
        if (mb_strlen((string)$this->data[$field]) > $max) {
            $this->errors[] = "Field '{$field}' must not exceed {$max} characters.";
        }
        return $this;
    }

    /**
     * Field, when present and non-empty, must be valid JSON.
     */
    public function json(string ...$fields): self
    {
        foreach ($fields as $field) {
            $val = $this->data[$field] ?? null;
            if ($val === null || trim((string)$val) === '') {
                continue;
            }
            json_decode((string)$val);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->errors[] = "Field '{$field}' must be valid JSON.";
            }
        }
        return $this;
    }

    /**
     * Add a custom error message directly (e.g. from a business-logic check).
     */
    public function addError(string $message): self
    {
        $this->errors[] = $message;
        return $this;
    }

    // ── Results ───────────────────────────────────────────────

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    /**
     * Returns the first validation error message, or an empty string.
     */
    public function firstError(): string
    {
        return $this->errors[0] ?? '';
    }

    /**
     * Returns all validation error messages.
     *
     * @return string[]
     */
    public function allErrors(): array
    {
        return $this->errors;
    }
}
