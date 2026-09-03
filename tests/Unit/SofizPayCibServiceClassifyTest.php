<?php

namespace Tests\Unit;

use App\Services\SofizPay\SofizPayCibService;
use Tests\TestCase;

class SofizPayCibServiceClassifyTest extends TestCase
{
    private SofizPayCibService $cib;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cib = new SofizPayCibService;
    }

    public function test_paid_check_is_classified_paid(): void
    {
        $this->assertSame('paid', $this->cib->classifyCheck([
            'respCode' => '00',
            'errorCode' => 0,
            'orderStatus' => 2,
            'Amount' => '3750.00',
        ]));
    }

    public function test_resp_code_17_is_canceled(): void
    {
        $this->assertSame('canceled', $this->cib->classifyCheck([
            'respCode' => '17',
            'errorCode' => 0,
            'orderStatus' => 0,
            'ResponseDescription' => 'Customer cancellation',
        ]));
    }

    public function test_order_status_3_is_canceled(): void
    {
        $this->assertSame('canceled', $this->cib->classifyCheck([
            'respCode' => '00',
            'errorCode' => 0,
            'orderStatus' => 3,
        ]));
    }

    public function test_order_status_6_is_failed(): void
    {
        $this->assertSame('failed', $this->cib->classifyCheck([
            'respCode' => '00',
            'errorCode' => 0,
            'orderStatus' => 6,
        ]));
    }

    public function test_other_resp_code_is_failed(): void
    {
        $this->assertSame('failed', $this->cib->classifyCheck([
            'respCode' => '05',
            'errorCode' => 0,
            'orderStatus' => 0,
        ]));
    }

    public function test_terminal_error_code_is_failed(): void
    {
        $this->assertSame('failed', $this->cib->classifyCheck([
            'errorCode' => 1,
            'errorMessage' => 'Gateway error',
        ]));
    }

    public function test_registered_and_acs_stay_pending(): void
    {
        $this->assertSame('pending', $this->cib->classifyCheck([
            'respCode' => '00',
            'errorCode' => 0,
            'orderStatus' => 0,
        ]));
        $this->assertSame('pending', $this->cib->classifyCheck([
            'respCode' => '00',
            'errorCode' => '0',
            'orderStatus' => 5,
        ]));
        $this->assertSame('pending', $this->cib->classifyCheck([]));
    }

    public function test_paid_takes_precedence_over_other_fields(): void
    {
        $this->assertSame('paid', $this->cib->classifyCheck([
            'respCode' => '00',
            'errorCode' => 0,
            'orderStatus' => 2,
            'ResponseDescription' => 'Customer cancellation',
        ]));
    }
}
