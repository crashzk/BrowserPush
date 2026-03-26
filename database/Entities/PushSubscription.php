<?php

namespace Flute\Modules\BrowserPush\database\Entities;

use Cycle\ActiveRecord\ActiveRecord;
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Relation\BelongsTo;
use Cycle\Annotated\Annotation\Table\Index;
use Cycle\ORM\Entity\Behavior;
use Flute\Core\Database\Entities\User;

#[Entity]
#[Behavior\CreatedAt(field: 'createdAt', column: 'created_at')]
#[Index(columns: ['user_id'])]
#[Index(columns: ['endpoint_hash'], unique: true)]
class PushSubscription extends ActiveRecord
{
    #[Column(type: 'primary')]
    public int $id;

    #[BelongsTo(target: User::class, nullable: false)]
    public User $user;

    #[Column(type: 'text')]
    public string $endpoint;

    #[Column(type: 'string(64)')]
    public string $endpoint_hash;

    #[Column(type: 'string(255)', nullable: true)]
    public ?string $p256dh = null;

    #[Column(type: 'string(255)', nullable: true)]
    public ?string $auth = null;

    #[Column(type: 'string(255)', nullable: true)]
    public ?string $user_agent = null;

    #[Column(type: 'datetime')]
    public \DateTimeImmutable $createdAt;

    public function setEndpoint(string $endpoint): void
    {
        $this->endpoint = $endpoint;
        $this->endpoint_hash = hash('sha256', $endpoint);
    }
}
