<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

final class OnlineUserPresenceService
{
    private const int ONLINE_WINDOW_MINUTES = 15;

    /**
     * @return list<int>
     */
    public function onlineUserIds(): array
    {
        return match (config('session.driver')) {
            'database' => $this->databaseOnlineUserIds(),
            'redis' => $this->redisOnlineUserIds(),
            default => [],
        };
    }

    public function recordActivity(int $userId): void
    {
        // Conditional write caps durable activity updates at once per minute per user.
        DB::table('users')->where('id', $userId)
            ->where(function ($query): void {
                $query->whereNull('last_active_at')
                    ->orWhere('last_active_at', '<=', now()->subMinute());
            })
            ->update(['last_active_at' => now()]);

        if (config('session.driver') !== 'redis') {
            return;
        }

        $connection = (string) (config('session.connection') ?: 'default');
        $key = $this->redisKey();
        $now = now()->timestamp;

        Redis::connection($connection)->zadd($key, [
            (string) $userId => $now,
        ]);

        Redis::connection($connection)->zremrangebyscore(
            $key,
            0,
            $now - (self::ONLINE_WINDOW_MINUTES * 60) - 1,
        );
    }

    /**
     * @return list<int>
     */
    private function databaseOnlineUserIds(): array
    {
        $connectionName = (string) (config('session.connection') ?: config('database.default'));
        $table = (string) config('session.table', 'sessions');

        if (! Schema::connection($connectionName)->hasTable($table)) {
            return [];
        }

        return $this->databaseConnection($connectionName)
            ->table($table)
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', now()->subMinutes(self::ONLINE_WINDOW_MINUTES)->timestamp)
            ->distinct()
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function redisOnlineUserIds(): array
    {
        $connection = (string) (config('session.connection') ?: 'default');
        $ids = Redis::connection($connection)->zrangebyscore(
            $this->redisKey(),
            now()->subMinutes(self::ONLINE_WINDOW_MINUTES)->timestamp,
            '+inf',
        );

        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            $ids,
        ));
    }

    private function databaseConnection(string $connection): Connection
    {
        return DB::connection($connection);
    }

    private function redisKey(): string
    {
        return config('cache.prefix', '').'online-users';
    }
}
