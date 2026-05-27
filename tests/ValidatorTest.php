<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  tests/ValidatorTest.php
//  PHPUnit 10 — unit tests for helpers/Validator.php
//
//  9 assertions covering: required, integer, numeric, min, max,
//  in, regex, maxLength, json, chaining, addError.
//
//  No database needed — pure in-memory validation.
//
//  Run:
//    php vendor/bin/phpunit --testdox tests/ValidatorTest.php
// ═══════════════════════════════════════════════════════════════

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/Validator.php';

class ValidatorTest extends TestCase
{
    // ── required ───────────────────────────────────────────────

    public function test_required_passes_when_all_fields_present(): void
    {
        $v = Validator::make(['session_id' => '5', 'segment_id' => '12'])
                ->required('session_id', 'segment_id');

        $this->assertTrue($v->passes());
        $this->assertFalse($v->fails());
    }

    public function test_required_fails_on_missing_field(): void
    {
        $v = Validator::make(['session_id' => '5'])
                ->required('session_id', 'segment_id');

        $this->assertTrue($v->fails());
        $this->assertStringContainsString("segment_id", $v->firstError());
    }

    public function test_required_fails_on_empty_string(): void
    {
        $v = Validator::make(['name' => '   '])
                ->required('name');

        $this->assertTrue($v->fails());
    }

    // ── integer ────────────────────────────────────────────────

    public function test_integer_passes_for_numeric_string(): void
    {
        $v = Validator::make(['id' => '42'])->integer('id');
        $this->assertTrue($v->passes());
    }

    public function test_integer_fails_for_float_string(): void
    {
        $v = Validator::make(['id' => '3.14'])->integer('id');
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('integer', $v->firstError());
    }

    // ── min / max ──────────────────────────────────────────────

    public function test_min_passes_when_value_at_boundary(): void
    {
        $v = Validator::make(['count' => '1'])->min('count', 1);
        $this->assertTrue($v->passes());
    }

    public function test_min_fails_when_value_below_minimum(): void
    {
        $v = Validator::make(['count' => '0'])->min('count', 1);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('at least 1', $v->firstError());
    }

    public function test_max_fails_when_value_exceeds_maximum(): void
    {
        $v = Validator::make(['score' => '101'])->max('score', 100);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('at most 100', $v->firstError());
    }

    // ── in ─────────────────────────────────────────────────────

    public function test_in_passes_for_allowed_value(): void
    {
        $v = Validator::make(['status' => 'active'])
                ->in('status', ['active', 'completed', 'pending']);
        $this->assertTrue($v->passes());
    }

    public function test_in_fails_for_disallowed_value(): void
    {
        $v = Validator::make(['status' => 'deleted'])
                ->in('status', ['active', 'completed', 'pending']);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('active', $v->firstError());
    }

    // ── regex ──────────────────────────────────────────────────

    public function test_regex_passes_for_matching_value(): void
    {
        // GPS coordinate pattern: decimal with up to 6 decimal places
        $v = Validator::make(['lat' => '18.520430'])
                ->regex('lat', '/^-?\d+\.\d+$/');
        $this->assertTrue($v->passes());
    }

    public function test_regex_fails_for_non_matching_value(): void
    {
        $v = Validator::make(['lat' => 'not-a-coordinate'])
                ->regex('lat', '/^-?\d+\.\d+$/');
        $this->assertTrue($v->fails());
    }

    // ── maxLength ──────────────────────────────────────────────

    public function test_maxLength_passes_at_exact_limit(): void
    {
        $v = Validator::make(['note' => str_repeat('x', 255)])
                ->maxLength('note', 255);
        $this->assertTrue($v->passes());
    }

    public function test_maxLength_fails_when_over_limit(): void
    {
        $v = Validator::make(['note' => str_repeat('x', 256)])
                ->maxLength('note', 255);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('255', $v->firstError());
    }

    // ── numeric ────────────────────────────────────────────────

    public function test_numeric_passes_for_float_string(): void
    {
        $v = Validator::make(['length' => '123.45'])->numeric('length');
        $this->assertTrue($v->passes());
    }

    public function test_numeric_fails_for_non_numeric_string(): void
    {
        $v = Validator::make(['length' => 'abc'])->numeric('length');
        $this->assertTrue($v->fails());
    }

    // ── json ───────────────────────────────────────────────────

    public function test_json_passes_for_valid_json_array(): void
    {
        $v = Validator::make(['issues' => '["crack","pothole"]'])->json('issues');
        $this->assertTrue($v->passes());
    }

    public function test_json_fails_for_invalid_json(): void
    {
        $v = Validator::make(['issues' => '{broken}'])->json('issues');
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('JSON', $v->firstError());
    }

    public function test_json_skips_empty_string(): void
    {
        // Empty optional JSON field should not produce an error
        $v = Validator::make(['issues' => ''])->json('issues');
        $this->assertTrue($v->passes());
    }

    // ── addError + allErrors ───────────────────────────────────

    public function test_addError_appends_custom_message(): void
    {
        $v = Validator::make([])
                ->addError('Road not found.')
                ->addError('Session expired.');

        $this->assertTrue($v->fails());
        $errors = $v->allErrors();
        $this->assertCount(2, $errors);
        $this->assertSame('Road not found.', $errors[0]);
        $this->assertSame('Session expired.', $errors[1]);
    }

    // ── chaining behaviour ─────────────────────────────────────

    public function test_chaining_accumulates_multiple_errors(): void
    {
        // Missing field + wrong type → both errors collected
        $v = Validator::make(['segment_id' => 'abc'])
                ->required('session_id', 'segment_id')  // session_id missing
                ->integer('segment_id');                 // segment_id not integer

        $this->assertTrue($v->fails());
        $this->assertCount(2, $v->allErrors());
    }

    public function test_chaining_returns_same_instance(): void
    {
        $v = Validator::make([]);
        $this->assertSame($v, $v->required('x'));
        $this->assertSame($v, $v->integer('x'));
        $this->assertSame($v, $v->min('x', 1));
    }

    // ── skip rules when field absent ──────────────────────────

    public function test_integer_skips_missing_optional_field(): void
    {
        // If a field is not in the data, integer/numeric/min/max/in/regex
        // rules should silently skip (they are presence-conditional)
        $v = Validator::make([])->integer('optional_id');
        $this->assertTrue($v->passes());
    }

    public function test_min_skips_missing_optional_field(): void
    {
        $v = Validator::make([])->min('page', 1);
        $this->assertTrue($v->passes());
    }
}
