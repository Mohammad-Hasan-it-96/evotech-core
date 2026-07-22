<?php

namespace Modules\DeviceSubscriptions\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Core\Domain\Concerns\HasUuid;
use Modules\DeviceSubscriptions\Database\Factories\DeviceNotificationFactory;

/**
 * A custom notification an operator dispatched — an offer, an update, an
 * announcement — and the record that it happened (ADR 0010). Non-tenant.
 *
 * `scope` is 'test' (a single-device dry run, sent to the operator's own phone
 * before committing) or 'broadcast' (an audience of an app's devices). Stored so
 * the console can show what went out, to whom, and by whom.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $app_name
 * @property string $scope
 * @property bool $active_only
 * @property string $title
 * @property string $body
 * @property string $type
 * @property int $recipients
 * @property string|null $target_device_id
 * @property string|null $sent_by
 * @property string|null $sent_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DeviceNotification extends Model
{
    public const SCOPE_TEST = 'test';

    public const SCOPE_BROADCAST = 'broadcast';

    /** The single machine key custom sends carry, echoed to the client as data.type. */
    public const TYPE_CUSTOM = 'custom_message';

    /** @use HasFactory<DeviceNotificationFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'app_name',
        'scope',
        'active_only',
        'title',
        'body',
        'type',
        'recipients',
        'target_device_id',
        'sent_by',
        'sent_by_name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active_only' => 'boolean',
            'recipients' => 'integer',
        ];
    }

    protected static function newFactory(): DeviceNotificationFactory
    {
        return DeviceNotificationFactory::new();
    }
}
