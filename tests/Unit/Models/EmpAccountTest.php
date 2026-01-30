<?php

namespace Tests\Unit\Models;

use App\Models\EmpAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmpAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_credentials_are_encrypted(): void
    {
        $account = EmpAccount::create([
            'name' => 'Test Account',
            'slug' => 'test-account',
            'endpoint' => 'gate.emerchantpay.net',
            'username' => 'test_username',
            'password' => 'test_password',
            'terminal_token' => 'test_token_123',
        ]);

        // Check raw database values are encrypted (not plain text)
        $rawAccount = \DB::table('emp_accounts')->where('id', $account->id)->first();
        
        $this->assertNotEquals('test_username', $rawAccount->username);
        $this->assertNotEquals('test_password', $rawAccount->password);
        $this->assertNotEquals('test_token_123', $rawAccount->terminal_token);

        // Check decrypted values are correct
        $account->refresh();
        $this->assertEquals('test_username', $account->username);
        $this->assertEquals('test_password', $account->password);
        $this->assertEquals('test_token_123', $account->terminal_token);
    }

    public function test_get_active_returns_active_account(): void
    {
        EmpAccount::create([
            'name' => 'Inactive Account',
            'slug' => 'inactive',
            'username' => 'user1',
            'password' => 'pass1',
            'terminal_token' => 'token1',
            'is_active' => false,
        ]);

        $activeAccount = EmpAccount::create([
            'name' => 'Active Account',
            'slug' => 'active',
            'username' => 'user2',
            'password' => 'pass2',
            'terminal_token' => 'token2',
            'is_active' => true,
        ]);

        $result = EmpAccount::getActive();

        $this->assertNotNull($result);
        $this->assertEquals($activeAccount->id, $result->id);
        $this->assertEquals('Active Account', $result->name);
    }

    public function test_get_active_returns_null_when_none_active(): void
    {
        EmpAccount::create([
            'name' => 'Inactive Account',
            'slug' => 'inactive',
            'username' => 'user1',
            'password' => 'pass1',
            'terminal_token' => 'token1',
            'is_active' => false,
        ]);

        $result = EmpAccount::getActive();

        $this->assertNull($result);
    }

    public function test_set_as_active_deactivates_others(): void
    {
        $account1 = EmpAccount::create([
            'name' => 'Account 1',
            'slug' => 'account-1',
            'username' => 'user1',
            'password' => 'pass1',
            'terminal_token' => 'token1',
            'is_active' => true,
        ]);

        $account2 = EmpAccount::create([
            'name' => 'Account 2',
            'slug' => 'account-2',
            'username' => 'user2',
            'password' => 'pass2',
            'terminal_token' => 'token2',
            'is_active' => false,
        ]);

        $account2->setAsActive();

        $account1->refresh();
        $account2->refresh();

        $this->assertFalse($account1->is_active);
        $this->assertTrue($account2->is_active);
    }

    public function test_ordered_scope_sorts_by_sort_order(): void
    {
        EmpAccount::create([
            'name' => 'Third',
            'slug' => 'third',
            'username' => 'u3',
            'password' => 'p3',
            'terminal_token' => 't3',
            'sort_order' => 3,
        ]);

        EmpAccount::create([
            'name' => 'First',
            'slug' => 'first',
            'username' => 'u1',
            'password' => 'p1',
            'terminal_token' => 't1',
            'sort_order' => 1,
        ]);

        EmpAccount::create([
            'name' => 'Second',
            'slug' => 'second',
            'username' => 'u2',
            'password' => 'p2',
            'terminal_token' => 't2',
            'sort_order' => 2,
        ]);

        $ordered = EmpAccount::ordered()->get();

        $this->assertEquals('First', $ordered[0]->name);
        $this->assertEquals('Second', $ordered[1]->name);
        $this->assertEquals('Third', $ordered[2]->name);
    }

    public function test_credentials_are_hidden_in_json(): void
    {
        $account = EmpAccount::create([
            'name' => 'Test Account',
            'slug' => 'test',
            'username' => 'secret_user',
            'password' => 'secret_pass',
            'terminal_token' => 'secret_token',
        ]);

        $json = $account->toArray();

        $this->assertArrayNotHasKey('username', $json);
        $this->assertArrayNotHasKey('password', $json);
        $this->assertArrayNotHasKey('terminal_token', $json);
        $this->assertArrayHasKey('name', $json);
        $this->assertArrayHasKey('slug', $json);
    }
}
