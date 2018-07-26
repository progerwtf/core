<?php

/*
 * This file is part of Flarum.
 *
 * (c) Toby Zerner <toby.zerner@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flarum\User;

use DateTime;
use Flarum\Database\AbstractModel;
use Flarum\User\Exception\InvalidConfirmationTokenException;

/**
 * @todo document database columns with @property
 */
class RegistrationToken extends AbstractModel
{
    /**
     * {@inheritdoc}
     */
    protected $table = 'auth_tokens';

    /**
     * {@inheritdoc}
     */
    protected $dates = ['created_at'];

    protected $casts = [
        'user_attributes' => 'array',
        'payload' => 'array'
    ];

    /**
     * Use a custom primary key for this model.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Generate an auth token for the specified user.
     *
     * @param array $payload
     * @return static
     */
    public static function generate(string $provider, string $identifier, array $attributes, array $payload)
    {
        $token = new static;

        $token->id = str_random(40);
        $token->provider = $provider;
        $token->identifier = $identifier;
        $token->user_attributes = $attributes;
        $token->payload = $payload;
        $token->created_at = time();

        return $token;
    }

    /**
     * Find the token with the given ID, and assert that it has not expired.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $id
     *
     * @throws \Flarum\User\Exception\InvalidConfirmationTokenException
     *
     * @return static
     */
    public function scopeValidOrFail($query, $id)
    {
        $token = $query->find($id);

        if (! $token || $token->created_at < new DateTime('-1 day')) {
            throw new InvalidConfirmationTokenException;
        }

        return $token;
    }
}
