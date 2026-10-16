<?php

namespace Tests\Feature\GraphQL;

final class QueryLimitsTest extends GraphQLTestCase
{
    public function test_queries_deeper_than_the_limit_are_rejected(): void
    {
        $deep = '{ schedule(locationId: "x", weekStart: "2026-10-19") { shifts { assignments { shift { assignments { shift { assignments { shift { assignments { staffMember { id } } } } } } } } } } }';

        $this->assertStringStartsWith('Max query depth should be 8 but got', $this->gql($deep)->json('errors.0.message'));
    }

    public function test_overly_complex_queries_are_rejected(): void
    {
        $fields = implode(' ', array_map(fn ($i) => "l{$i}: locations { id name timezone rulePack }", range(1, 70)));

        $message = $this->gql("{ {$fields} }")->json('errors.0.message');

        $this->assertStringStartsWith('Max query complexity should be 300', $message);
    }
}
