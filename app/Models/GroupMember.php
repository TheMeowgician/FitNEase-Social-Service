<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GroupMember extends Model
{
    use HasFactory;

    protected $primaryKey = 'group_member_id';

    protected $fillable = [
        'group_id',
        'user_id',
        'member_role',
        'joined_at',
        'is_active'
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id', 'group_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAdmins($query)
    {
        return $query->where('member_role', 'admin');
    }

    public function scopeModerators($query)
    {
        return $query->where('member_role', 'moderator');
    }

    public function scopeRegularMembers($query)
    {
        return $query->where('member_role', 'member');
    }

    public function isAdmin()
    {
        return $this->member_role === 'admin';
    }

    public function isModerator()
    {
        return $this->member_role === 'moderator';
    }

    public function canManageGroup()
    {
        return in_array($this->member_role, ['admin', 'moderator']);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($groupMember) {
            if (empty($groupMember->joined_at)) {
                $groupMember->joined_at = now();
            }
        });

        static::created(function ($groupMember) {
            if ($groupMember->is_active) {
                $group = $groupMember->group;
                $group->increment('current_member_count');
            }
        });

        static::updated(function ($groupMember) {
            if ($groupMember->isDirty('is_active')) {
                $group = $groupMember->group;
                if ($groupMember->is_active) {
                    $group->increment('current_member_count');
                } else {
                    $group->decrement('current_member_count');
                }
            }
        });

        static::deleted(function ($groupMember) {
            if ($groupMember->is_active) {
                $group = $groupMember->group;
                $group->decrement('current_member_count');
            }
        });
    }
}
