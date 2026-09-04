<?php

namespace Banimark\Laravel\Console;

use Banimark\Auth\Agents;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Create a staff account from the CLI (first owner, or extra staff). */
class AgentCommand extends Command
{
    protected $signature = 'banimark:agent {--owner : make this an owner}';
    protected $description = 'Add a Banimark staff/agent account';

    public function handle(): int
    {
        $agents = new Agents(DB::connection()->getPdo());
        $email = (string) $this->ask('Email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email.');
            return self::FAILURE;
        }
        $pass = (string) $this->secret('Password (min 8, hidden)');
        if (strlen($pass) < 8) {
            $this->error('Password too short.');
            return self::FAILURE;
        }
        $role = $this->option('owner') || $agents->count() === 0 ? 'owner' : 'agent';
        $ok = $agents->create((string) $this->ask('Name', 'Agent'), $email, $pass, $role);
        if ($ok === false) {
            $this->error('That email is already a staff account.');
            return self::FAILURE;
        }
        $this->info('Created '.$role.' account: '.$email);
        return self::SUCCESS;
    }
}
