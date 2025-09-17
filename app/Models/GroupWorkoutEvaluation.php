<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GroupWorkoutEvaluation extends Model
{
    use HasFactory;

    protected $primaryKey = 'evaluation_id';

    protected $fillable = [
        'group_id',
        'workout_id',
        'user_id',
        'evaluation_type',
        'comment'
    ];

    protected $casts = [
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

    public function scopeLikes($query)
    {
        return $query->where('evaluation_type', 'like');
    }

    public function scopeUnlikes($query)
    {
        return $query->where('evaluation_type', 'unlike');
    }

    public function scopeForWorkout($query, $workoutId)
    {
        return $query->where('workout_id', $workoutId);
    }

    public function scopeForGroup($query, $groupId)
    {
        return $query->where('group_id', $groupId);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeRecent($query, $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function isLike()
    {
        return $this->evaluation_type === 'like';
    }

    public function isUnlike()
    {
        return $this->evaluation_type === 'unlike';
    }

    public function hasComment()
    {
        return !empty($this->comment);
    }

    public static function getWorkoutStats($groupId, $workoutId)
    {
        $evaluations = self::where('group_id', $groupId)
            ->where('workout_id', $workoutId)
            ->get();

        return [
            'total_evaluations' => $evaluations->count(),
            'likes' => $evaluations->where('evaluation_type', 'like')->count(),
            'unlikes' => $evaluations->where('evaluation_type', 'unlike')->count(),
            'comments' => $evaluations->whereNotNull('comment')->where('comment', '!=', '')->count(),
            'like_percentage' => $evaluations->count() > 0
                ? round(($evaluations->where('evaluation_type', 'like')->count() / $evaluations->count()) * 100, 1)
                : 0
        ];
    }

    public static function getUserEvaluation($groupId, $workoutId, $userId)
    {
        return self::where('group_id', $groupId)
            ->where('workout_id', $workoutId)
            ->where('user_id', $userId)
            ->first();
    }

    public static function getPopularWorkouts($groupId, $limit = 10)
    {
        return self::select('workout_id')
            ->selectRaw('COUNT(*) as total_evaluations')
            ->selectRaw('SUM(CASE WHEN evaluation_type = "like" THEN 1 ELSE 0 END) as likes')
            ->selectRaw('SUM(CASE WHEN evaluation_type = "unlike" THEN 1 ELSE 0 END) as unlikes')
            ->selectRaw('(SUM(CASE WHEN evaluation_type = "like" THEN 1 ELSE 0 END) / COUNT(*)) * 100 as like_percentage')
            ->where('group_id', $groupId)
            ->groupBy('workout_id')
            ->orderByDesc('like_percentage')
            ->orderByDesc('total_evaluations')
            ->limit($limit)
            ->get();
    }
}
