<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Integration');
pest()->use(DatabaseTransactions::class)->in('Feature');
