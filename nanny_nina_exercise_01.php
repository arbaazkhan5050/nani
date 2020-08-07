<?php
namespace App\Traits;

use App\Models\Booking\Occasional\OccasionalBooking;
use App\Models\Location\Area;
use App\Models\User\UserBookingBlacklist;
use App\Notifications\Booking\Occasional\OccasionalBookingEmptyInvites;
use App\Traits\DistanceTrait;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

trait OccasionalBookingInviteManagerTrait
{
    use DistanceTrait;


    //process booking
    public function processOccasionalBookingInvites(OccasionalBooking $occasionalBooking)
    {
    
        $babysitters = $this->getAvailableBabysittersByArea($occasionalBooking->area, $occasionalBooking);

        $babysitters = $this->getBabysittersWithActiveType($babysitters);

        $babysitter = $this->getBabysitterWithMatchingRequirements($babysitters, $occasionalBooking);

        $babysitters = $this->removeAlreadyInvitedBabysitters($babysitters, $occasionalBooking);

        $babysitters = $this->sortBabysitterByDistance($babysitters, $occasionalBooking);

        if($babysitters->count() == 0 && Carbon::now()->diffInHours($occasionalBooking->time_start) < 24)
        {
            $occasionalBooking->area->captain->notify(new OccasionalBookingEmptyInvites($occasionalBooking));
        }

        return $babysitters;
    }

    //list of matching baby sitters
    public function getListOfMatchingBabysitters(OccasionalBooking $occasionalBooking)
    {
   
        $babysitters = $this->getAvailableBabysittersByArea($getAvailableBabysitters, $occasionalBooking);
        $babysitters = $this->getBabysittersWithActiveType($babysitters);
        $babysitter = $this->getBabysitterWithMatchingRequirements($babysitters, $occasionalBooking);
        $babysitters = $this->removeAlreadyInvitedBabysitters($babysitters, $occasionalBooking);
        $babysitters = $this->sortBabysitterByDistance($babysitters, $occasionalBooking);

        return $babysitters;
    }


    //total sitters
    public function getTotalsOfMatchingBabysitters(OccasionalBooking $occasionalBooking)
    {
        $array = [];

        $babysitters = $this->getAvailableBabysittersByArea($occasionalBooking->area, $occasionalBooking, true);
        $array['all_active_babysitters'] = $babysitters['activeSitters'];
        $array['all_available_babysitters'] = $babysitters['allBabysitters']->count();

        $babysitters = $this->getBabysittersWithActiveType($babysitters);
        $array['all_active_type_babysitters'] = $babysitters->count();

        $babysitter = $this->getBabysitterWithMatchingRequirements($babysitters, $occasionalBooking);
        $array['all_matching_requirement_babysitters'] = $babysitters->count();

        $babysitters = $this->removeAlreadyInvitedBabysitters($babysitters, $occasionalBooking);
        $array['all_without_invited_babysitters'] = $babysitters->count();

        $babysitters = $this->sortBabysitterByDistance($babysitters, $occasionalBooking);
        $array['all_within_distance_babysitters'] = $babysitters->count();

        return $array;
    }



    //get all availabe baby sitters by area
    public function getAvailableBabysittersByArea(Area $area, OccasionalBooking $occasionalBooking)
    {

        //get time slots
        $slots = $this->getWeekCalenderSlots($occasionalBooking);

        $slot1 = $slots[0];
        $slot2 = $slots[1];


        //baby sitter by area
        $babysitters = Area::find($area->id)->babysitters()->whereHas('detail', function($query) {
            $query->where('is_active', true);
        })->get();

        $active_sitters = count($babysitters);

        $blacklistedBabysitters = UserBookingBlacklist::where('parent_id', $occasionalBooking->parent_id)->get();

        if($blacklistedBabysitters->count() > 0)
        {
            $babysitters = $babysitters->forget($blacklistedBabysitters->pluck('babysitter_id'));
        }

        $babysitters = User::whereIn('id', $babysitters->pluck('id'))->whereHas('weekSchedule', function($query) use ($slot1, $slot2) {
            $query->where($slot1, true)->where($slot2, true);
        })->get();

      
        return  array(
            'allBabysitters' => $babysitters, 
            'activeSitters' => $active_sitters   
        );
     
    }

    public function getBabysittersWithActiveType($babysitters)
    {
        foreach($babysitters as $i=>$babysitter)
        {
            if(!$babysitter->notificationPreference->is_receive_request)
            {
                $this->forgetById($babysitters, $babysitter->id);
            }

            if(is_null($babysitter->profile->babysitter_types) || !in_array('occasional', $babysitter->profile->babysitter_types))
            {
                $this->forgetById($babysitters, $babysitter->id);
            }
        }

        return $babysitters;
    }


    //baby sitter matching requirments
    public function getBabysitterWithMatchingRequirements($babysitters, OccasionalBooking $occasionalBooking)
    {
        $requirements = $occasionalBooking->requirements;
        $maxDayRate = null;
       
        foreach($babysitters as $i=>$babysitter)
        {            
            $certificates = $babysitter->profile->certificates;

            $this->babySittersRequirment($requirements);

            if(!is_null($maxDayRate))
            {
                $this->sitterRates($babysitter);
            }
        }

        return $babysitters;
    }

    

    //already invited sitters
    public function removeAlreadyInvitedBabysitters($babysitters, OccasionalBooking $occasionalBooking)
    {
        $invites = $occasionalBooking->invites;
        
        foreach($babysitters as $i=>$babysitter)
        {
            if($invites->where('babysitter_id', $babysitter->id)->count() > 0)
            {
                $this->forgetById($babysitters, $babysitter->id);
            }
        }

        return $babysitters;
    }

    //sort by distance
    private function sortBabysitterByDistance($babysitters, OccasionalBooking $occasionalBooking)
    {
        $parentPostalCode = $occasionalBooking->parent->postalCode;

        foreach($babysitters as $i=>$babysitter)
        {
            $this->manageBabySittersDistance($babysitter);
        }

       return $this->sortBabySitters($babysitters);
    }
       
    //sitters base on there rates
    function sitterRates($babysitter){
        $babysitterRate = (!is_null($babysitter->profile->day_rate) ? $babysitter->profile->day_rate : 999);
        if($babysitterRate >= $maxDayRate){
            $this->forgetById($babysitters, $babysitter->id);
        }
    }

    // sitter matching requirments
    function babySittersRequirment($requirements){
        foreach($requirements as $i=>$requirement)
        {

            if(Str::startsWith($requirement, 'maxDayRate:'))
            {
                $maxDayRate = Str::replaceFirst('maxDayRate:', '', $requirement);
                unset($requirements[$i]);
            }

            if(in_array($requirement, ['cooking', 'sport', 'laundry']))
            {
                unset($requirements[$i]);
            }

            if(!is_null($certificates))
            {
                if(!in_array($requirement, $certificates))
                {
                    $this->forgetById($babysitters, $babysitter->id);
                }
            }
            else
            {
                $this->forgetById($babysitters, $babysitter->id);
            }
        }
    }
  
    //manage Distane
    function manageBabySittersDistance(){
          
            $babysitter->distance = $this->getDistanceBetweenPostalCodes($babysitter->postalCode, $parentPostalCode);

            if($babysitter->distance > ($babysitter->profile->max_distance - 2) || $babysitter->distance > 12)
            {
                $this->forgetById($babysitters, $babysitter->id);
            }

    }

    //sorted baby sitters
    public function sortBabySitters($babysitters){
        if($babysitters->count() > 0){
            $sortedBabysitters = $babysitters->sortBy(function($babysitters)
            {
                return $babysitters->distance;
            });
        }else{
            $sortedBabysitters = $babysitters;
        }
        return $sortedBabysitters;
    }

    public function getWeekCalenderSlots(OccasionalBooking $occasionalBooking)
    {
        $weekDay = $occasionalBooking->time_start->dayOfWeek;
        $timeStart = $occasionalBooking->time_start;
        $timeEnd = $occasionalBooking->time_end;

        $weekDaySlot = $this->weekDays($weekDay);
        return $this->createTime($timeStart->hour , $weekDaySlot);
    }

    //get week days
    function weekDays($weekDaySlot)
    {

        $weekDaySlot = '';
        switch ($weekDay)
        {
            case 0: $weekDaySlot = 'sunday'; break;
            case 1: $weekDaySlot = 'monday'; break;
            case 2: $weekDaySlot = 'tuesday'; break;
            case 3: $weekDaySlot = 'wednesday'; break;
            case 4: $weekDaySlot = 'thursday'; break;
            case 5: $weekDaySlot = 'friday'; break;
            case 6: $weekDaySlot = 'saturday'; break;            
            default: $weekDaySlot = 'monday'; break;
        }
        return $weekDaySlot;
        
    }


    //create time
    function createTime($hour , $weekDaySlot)
    {
        $timeStartSlot = $this->startTime($hour);
        $timeEndSlot = $this->endTime($hour);

        $timeStartSlot = $weekDaySlot . '_' . $timeStartSlot;
        $timeEndSlot = $weekDaySlot . '_' . $timeEndSlot;
        return [$timeStartSlot, $timeEndSlot];
    }

    //get start time
    function startTime($hour)
    {
        $timeStartSlot = '';
        if(in_array($hour, [7, 8, 9, 10, 11, 12])){
            $timeStartSlot = 'morning';
        }else if(in_array($hour, [13, 14, 15, 16, 17])){
            $timeStartSlot = 'afternoon';
        }else{
            $timeStartSlot = 'evening';
        }

        return $timeStartSlot;
    }

     //get end time
    function endTime($hour)
    {
        $timeEndSlot = '';
        if(in_array($timeEnd->hour, [7, 8, 9, 10, 11, 12]))
        {
            $timeEndSlot = 'morning';
        }
        else if(in_array($timeEnd->hour, [13, 14, 15, 16, 17]))
        {
            $timeEndSlot = 'afternoon';
        }
        else
        {
            $timeEndSlot = 'evening';
        }


        return $timeEndSlot;
    }

    function forgetById($collection, $id)
    {
        foreach($collection as $key => $item)
        {
            if($item->id == $id)
            {
                $collection->forget($key);
                break;
            }
        }

        return $collection;
    }
}
