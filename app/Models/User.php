<?php

namespace App\Models;

use Illuminate\Auth\GenericUser;

/**
 * 中央账号在游戏中心会话中的只读身份快照。
 *
 * 该对象不连接数据库；账号与权限的唯一来源仍是 next-api。
 */
class User extends GenericUser
{
    public int $id;

    public string $name;

    public ?string $email;

    public bool $is_admin;

    /** @var array<int, string> */
    public array $permissions;

    /**
     * @param  array{id:int,name:string,email?:string|null,is_admin?:bool,permissions?:array<int,string>}  $attributes
     */
    public function __construct(array $attributes)
    {
        $this->id = (int) $attributes['id'];
        $this->name = (string) $attributes['name'];
        $this->email = isset($attributes['email']) ? (string) $attributes['email'] : null;
        $this->is_admin = (bool) ($attributes['is_admin'] ?? false);
        $this->permissions = array_values(array_unique(array_map('strval', $attributes['permissions'] ?? [])));

        parent::__construct($this->toArray());
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function hasRole(string $role): bool
    {
        return $role === 'admin' && $this->isAdmin();
    }

    public function can(string $ability, $arguments = []): bool
    {
        return $this->isAdmin() || in_array($ability, $this->permissions, true);
    }

    /** @return array{id:int,name:string,email:string|null,is_admin:bool,permissions:array<int,string>} */
    public function toArray(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'email' => $this->email !== null ? (string) $this->email : null,
            'is_admin' => $this->isAdmin(),
            'permissions' => $this->permissions,
        ];
    }
}
