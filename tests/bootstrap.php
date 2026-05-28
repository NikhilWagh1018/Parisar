<?php
declare(strict_types=1);

// Load Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Manually load files that are no longer in autoload.files
// (removed to fix circular load crash on Railway)
require_once dirname(__DIR__) . '/services/ScoreHelpers.php';
require_once dirname(__DIR__) . '/services/ScoreService.php';
require_once dirname(__DIR__) . '/helpers/Validator.php';
require_once dirname(__DIR__) . '/repositories/SegmentRepository.php';
