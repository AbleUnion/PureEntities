<?php

namespace milk\pureentities\entity;

use milk\pureentities\entity\animal\Animal;
use milk\pureentities\entity\monster\flying\Blaze;
use pocketmine\math\Math;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\entity\Creature;

abstract class FlyingEntity extends EntityBase{

    protected function checkTarget(){
        if($this->attackTime > 0){
            return;
        }

        $target = $this->target;
        if(!($target instanceof Creature) or !$this->targetOption($target, $this->distanceSquared($target))){
            $near = PHP_INT_MAX;
            foreach ($this->getLevel()->getEntities() as $creature){
                if($creature === $this || !($creature instanceof Creature) || $creature instanceof Animal){
                    continue;
                }

                if($creature instanceof EntityBase && $creature->isFriendly() === $this->isFriendly()){
                    continue;
                }

                if(($distance = $this->distanceSquared($creature)) > $near or !$this->targetOption($creature, $distance)){
                    continue;
                }

                $near = $distance;
                $this->target = $creature;
            }
        }

        if(
            $this->target instanceof Creature
            && $this->target->isAlive()
        ){
            return;
        }

        $maxY = max($this->getLevel()->getHighestBlockAt((int) $this->x, (int) $this->z) + 15, 120);
        if($this->moveTime <= 0 or !$this->target instanceof Vector3){
            $x = mt_rand(20, 100);
            $z = mt_rand(20, 100);
            if($this->y > $maxY){
                $y = mt_rand(-12, -4);
            }else{
                $y = mt_rand(-10, 10);
            }
            $this->moveTime = mt_rand(300, 1200);
            $this->target = $this->add(mt_rand(0, 1) ? $x : -$x, $y, mt_rand(0, 1) ? $z : -$z);
        }
    }

    public function updateMove($tickDiff){
        if(!$this->isMovement()){
            return null;
        }

        if($this->attackTime > 0){
            $this->move($this->motionX * $tickDiff, $this->motionY * $tickDiff, $this->motionZ * $tickDiff);
            $this->updateMovement();
            return null;
        }
        
        $before = $this->target;
        $this->checkTarget();
        if($this->target instanceof Player or $before !== $this->target){
            $x = $this->target->x - $this->x;
            $y = $this->target->y - $this->y;
            $z = $this->target->z - $this->z;

            $diff = abs($x) + abs($z);
            if($x ** 2 + $z ** 2 < 0.5){
                $this->motionX = 0;
                $this->motionZ = 0;
            }else{
                $this->motionX = $this->getSpeed() * 0.15 * ($x / $diff);
                $this->motionZ = $this->getSpeed() * 0.15 * ($z / $diff);
                $this->motionY = $this->getSpeed() * 0.27 * ($y / $diff);
            }
            $this->yaw = rad2deg(-atan2($x / $diff, $z / $diff));
            $this->pitch = $y === 0 ? 0 : rad2deg(-atan2($y, sqrt($x ** 2 + $z ** 2)));
        }

        $target = $this->target;
        $isJump = \false;
        $dx = $this->motionX * $tickDiff;
        $dy = $this->motionY * $tickDiff;
        $dz = $this->motionZ * $tickDiff;

        $be = new Vector2($this->x + $dx, $this->z + $dz);
        $this->move($dx, $dy, $dz);
        $af = new Vector2($this->x, $this->z);

        if($be->x !== $af->x || $be->y !== $af->y){
            if($this instanceof Blaze){
                $x = 0;
                $z = 0;
                if($be->x - $af->x !== 0){
                    $x = $be->x > $af->x ? 1 : -1;
                }
                if($be->y - $af->y !== 0){
                    $z = $be->y > $af->y ? 1 : -1;
                }

                $vec = new Vector3(Math::floorFloat($be->x) + $x, $this->y, Math::floorFloat($be->y) + $z);
                $block = $this->level->getBlock($vec->add($x, 0, $z));
                $block2 = $this->level->getBlock($vec->add($x, 1, $z));
                if(!$block->canPassThrough()){
                    $bb = $block2->getBoundingBox();
                    if(
                        $this->motionY > -$this->gravity * 4
                        && ($block2->canPassThrough() || ($bb === null || $bb->maxY - $this->y <= 1))
                    ){
                        $isJump = \true;
                        if($this->motionY >= 0.3){
                            $this->motionY += $this->gravity;
                        }else{
                            $this->motionY = 0.3;
                        }
                    }
                }

                if(!$isJump){
                    $this->moveTime -= 90 * $tickDiff;
                }
            }else{
                $this->moveTime -= 90 * $tickDiff;
            }
        }

        if($this instanceof Blaze){
            if($this->onGround && !$isJump){
                $this->motionY = 0;
            }else if(!$isJump){
                if($this->motionY > -$this->gravity * 4){
                    $this->motionY = -$this->gravity * 4;
                }else{
                    $this->motionY -= $this->gravity;
                }
            }
        }
        $this->updateMovement();
        return $target;
    }

}