<?php

namespace milk\pureentities\entity;

use milk\pureentities\entity\monster\flying\Blaze;
use milk\pureentities\entity\monster\Monster;
use pocketmine\entity\Creature;
use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Timings;
use pocketmine\math\Math;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\Player;

abstract class EntityBase extends Creature{

    protected $speed = 1;

    protected $stayTime = 0;
    protected $moveTime = 0;

    /** @var Vector3|Entity */
    protected $target = \null;

    /** @var Vector3|Entity */
    protected $followTarget = \null;

    private $movement = \true;
    private $friendly = \false;
    private $wallcheck = \true;

    public function __destruct(){}

    public abstract function updateMove($tickDiff);

    public function getSaveId(){
        $class = new \ReflectionClass(get_class($this));
        return $class->getShortName();
    }

    public function isMovement(){
        return $this->movement;
    }

    public function isFriendly(){
        return $this->friendly;
    }

    public function isWallCheck(){
        return $this->wallcheck;
    }

    public function setMovement($value){
        $this->movement = $value;
    }

    public function setFriendly($bool){
        $this->friendly = $bool;
    }

    public function setWallCheck($value){
        $this->wallcheck = $value;
    }

    public function getSpeed(){
        return $this->speed;
    }

    public function getTarget(){
        return $this->followTarget != null ? $this->followTarget : ($this->target instanceof Entity ? $this->target : null);
    }

    public function setTarget(Entity $target){
        $this->followTarget = $target;
        
        $this->moveTime = 0;
        $this->stayTime = 0;
        $this->target = \null;
    }
    
    public function initEntity(){
        parent::initEntity();

        if(isset($this->namedtag->Movement)){
            $this->setMovement($this->namedtag["Movement"]);
        }
        if(isset($this->namedtag->Friendly)){
            $this->setFriendly($this->namedtag["Friendly"]);
        }
        if(isset($this->namedtag->WallCheck)){
            $this->setWallCheck($this->namedtag["WallCheck"]);
        }
        $this->setImmobile(\true);
    }

    public function saveNBT(){
        parent::saveNBT();
        $this->namedtag->Movement = new ByteTag("Movement", $this->isMovement());
        $this->namedtag->Friendly = new ByteTag("Friendly", $this->isFriendly());
        $this->namedtag->WallCheck = new ByteTag("WallCheck", $this->isWallCheck());
    }

    public function updateMovement(){
        if(
            $this->lastX !== $this->x
            || $this->lastY !== $this->y
            || $this->lastZ !== $this->z
            || $this->lastYaw !== $this->yaw
            || $this->lastPitch !== $this->pitch
        ){
            $this->lastX = $this->x;
            $this->lastY = $this->y;
            $this->lastZ = $this->z;
            $this->lastYaw = $this->yaw;
            $this->lastPitch = $this->pitch;
        }
        $this->broadcastMovement();
    }

    public function isInsideOfSolid() : bool{
        $block = $this->level->getBlock($this->temporalVector->setComponents(Math::floorFloat($this->x), Math::floorFloat($this->y + $this->height - 0.18), Math::floorFloat($this->z)));
        $bb = $block->getBoundingBox();
        return $bb !== null and $block->isSolid() and !$block->isTransparent() and $bb->intersectsWith($this->getBoundingBox());
    }

    public function attack(EntityDamageEvent $source){
        if($this->attackTime > 0) return;

        parent::attack($source);

        if($source->isCancelled() || !($source instanceof EntityDamageByEntityEvent)){
            return;
        }

        $this->stayTime = 0;
        $this->moveTime = 0;

        $damager = $source->getDamager();
        $motion = (new Vector3($this->x - $damager->x, $this->y - $damager->y, $this->z - $damager->z))->normalize();
        $this->motionX = $motion->x * 0.19;
        $this->motionZ = $motion->z * 0.19;
        if(($this instanceof FlyingEntity) && !($this instanceof Blaze)){
            $this->motionY = $motion->y * 0.19;
        }else{
            $this->motionY = 0.6;
        }
    }

    public function knockBack(Entity $attacker, float $damage, float $x, float $z, float $base = 0.4){

    }

    public function entityBaseTick(int $tickDiff = 1) : bool{
        $hasUpdate = Entity::entityBaseTick($tickDiff);

        if($this->isInsideOfSolid()){
            $hasUpdate = \true;
            $ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_SUFFOCATION, 1);
            $this->attack($ev);
        }

        if($this->moveTime > 0){
            $this->moveTime -= $tickDiff;
        }
        if($this->attackTime > 0){
            $this->attackTime -= $tickDiff;
        }
        return $hasUpdate;
    }

    public function move(float $dx, float $dy, float $dz) : bool{
        Timings::$entityMoveTimer->startTiming();

        $movX = $dx;
        $movY = $dy;
        $movZ = $dz;

        $list = $this->level->getCollisionCubes($this, $this->level->getTickRate() > 1 ? $this->boundingBox->getOffsetBoundingBox($dx, $dy, $dz) : $this->boundingBox->addCoord($dx, $dy, $dz));
        if($this->isWallCheck()){
            foreach($list as $bb){
                $dx = $bb->calculateXOffset($this->boundingBox, $dx);
            }
            $this->boundingBox->offset($dx, 0, 0);

            foreach($list as $bb){
                $dz = $bb->calculateZOffset($this->boundingBox, $dz);
            }
            $this->boundingBox->offset(0, 0, $dz);
        }
        foreach($list as $bb){
            $dy = $bb->calculateYOffset($this->boundingBox, $dy);
        }
        $this->boundingBox->offset(0, $dy, 0);

        $this->setComponents($this->x + $dx, $this->y + $dy, $this->z + $dz);
        $this->checkChunks();

        $this->checkGroundState($movX, $movY, $movZ, $dx, $dy, $dz);
        $this->updateFallState($dy, $this->onGround);

        Timings::$entityMoveTimer->stopTiming();
        return \true;
    }

    public function targetOption(Creature $creature, $distance){
        return $this instanceof Monster && (!($creature instanceof Player) || ($creature->isSurvival() && $creature->spawned)) && $creature->isAlive() && !$creature->closed && $distance <= 81;
    }

}