<?php

namespace App\Jobs;

use App\Rank;
use App\Customer;
use App\Earning;
use App\User;
use Carbon\Carbon;
use App\SalesBonusDetail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesBonusCalculateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    protected static $childs = [];
    protected static $user_id = [1];
    protected static $customers = [];
    protected static $upline = [];
    protected $parent_id;
    public $tries = 5;


    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $parent_id)
    {
        $this->parent_id = $parent_id;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        ini_set('max_execution_time', 0);
        $upline_user_ids = $this->getUplineIDs($this->parent_id);

        $users = User::select('id')
            ->where('role_id', 3)
            ->whereIn('id', $upline_user_ids)
            ->with(['customer', 'salesBonusDetail'])
            ->get();

        $now = now()->toDateTimeString();
        $update_is_paired_ids = [];

        foreach ($users as $user) {

            //continue inactive customer
            $customer = $user->customer;
            if($customer->status == 0){
                //dump(['continue 1', $user->id]);
                continue;
            }

            self::$user_id = [$user->id];
            self::$childs = [];

            $right_last_earning = $user->salesBonusDetail->where('position', Customer::POSITION_RIGHT)->last();
            $left_last_earning = $user->salesBonusDetail->where('position', Customer::POSITION_LEFT)->last();


            $left_childs = implode(',', $this->getAllChilds(Customer::POSITION_LEFT));

            self::$childs = [];
            self::$user_id = [$user->id];

            $right_childs = implode(',', $this->getAllChilds(Customer::POSITION_RIGHT));

            //dd([$left_childs, $right_childs]);

            $left_childs_bv = 0;
            $right_childs_bv = 0;

            if (!empty($left_childs)) {
                $sale = DB::select(DB::raw("select sum((quantity*bv)-discount) as total_bv from sale_details where user_id IN($left_childs)"));
                $left_childs_bv += $sale[0]->total_bv ?? 0;
            }

            if (!empty($right_childs)) {
                $sale = DB::select(DB::raw("select sum((quantity*bv)-discount) as total_bv from sale_details where user_id IN ($right_childs)"));
                $right_childs_bv += $sale[0]->total_bv ?? 0;
            }

            if(empty($left_childs) && empty($right_childs)) {
                //dump(['continue 2', $user->id]);
                continue;
            }


            $update_is_paired_ids = array_filter(array_merge(
                array_merge(
                    explode(',', $left_childs),
                    explode(',', $right_childs)
                ), $update_is_paired_ids));

            //dd($update_is_paired_ids);
            //dump([$left_childs_bv, $right_childs_bv]);
            $left_childs_bv += $left_last_earning->carry_forward ?? 0;
            $right_childs_bv += $right_last_earning->carry_forward ?? 0;

//            dump(empty($right_childs) && !empty($left_childs), $user->id);
//            dump(!empty($right_childs) && empty($left_childs), $user->id);
//            dd([$left_childs_bv, $right_childs_bv]);

            if(empty($left_childs_bv) && empty($right_childs_bv)) {
                continue;
            } elseif (empty($right_childs_bv) && !empty($left_childs_bv)) {
                $this->noWeakerSideFound(Customer::POSITION_LEFT, $left_childs_bv, $user->id);
                continue;
            } elseif (!empty($right_childs_bv) && empty($left_childs_bv)) {
                $this->noWeakerSideFound(Customer::POSITION_RIGHT, $right_childs_bv, $user->id);
                continue;
            }

            //dd([$user, $left_childs_bv, $right_childs_bv, $left_childs, $right_childs]);

            $left_points = ($left_childs_bv / 100) * $user->customer->criteria->percentage;
            $right_points = ($right_childs_bv / 100) * $user->customer->criteria->percentage;

            //dd([$user, $left_childs_bv, $right_childs_bv, $left_childs, $right_childs, $left_points, $right_points]);

            //dump($left_childs_bv < $right_childs_bv);
            $sales_bonus_detail = [];
            $carry_forward = 0;
            if (round($left_childs_bv) < round($right_childs_bv)) { // Left Weaker

                $carry_forward = $right_childs_bv - $left_childs_bv;

                $sales_bonus_detail = [
                    [
                        'user_id' => $user->id,
                        'sales_bonus' => $left_points,
                        'carry_forward' => 0,
                        'position' => Customer::POSITION_LEFT,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    [
                        'user_id' => $user->id,
                        'sales_bonus' => 0,
                        'carry_forward' => $carry_forward,
                        'position' => Customer::POSITION_RIGHT,
                        'created_at' => $now,
                        'updated_at' => $now,

                    ]
                ];

            }
            //dump($right_childs_bv < $left_childs_bv);
            if (round($right_childs_bv) < round($left_childs_bv)) { //Right Weaker

                $carry_forward = $left_childs_bv - $right_childs_bv;

                $sales_bonus_detail = [
                    [
                        'user_id' => $user->id,
                        'sales_bonus' => $right_points,
                        'carry_forward' => 0,
                        'position' => Customer::POSITION_RIGHT,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    [
                        'user_id' => $user->id,
                        'sales_bonus' => 0,
                        'carry_forward' => $carry_forward,
                        'position' => Customer::POSITION_LEFT,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                ];

            }
            //dump(['right_child_bv'=>$right_childs_bv, 'left_child_bv'=> $left_childs_bv]);
            //dump([$left_points, $right_points, !empty($carry_forward) ? $carry_forward : 'cf=0', !empty($sales_bonus_detail) ? $sales_bonus_detail : 'sales_detail=[]', $user->id]);

//            if(($left_points > 0) || ($right_points > 0)){

                if(!empty($sales_bonus_detail)) {
                    SalesBonusDetail::insert($sales_bonus_detail);
                    $this->updateEarning($user->id, ($left_points < $right_points ? $left_points : $right_points), !empty($carry_forward) ? $carry_forward : 0);
                }else{
                    //points are equal
                    $sales_bonus_detail = array(
                        [
                            'user_id' => $user->id,
                            'sales_bonus' => $left_points,
                            'carry_forward' => 0,
                            'position' => Customer::POSITION_LEFT,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                        [
                            'user_id' => $user->id,
                            'sales_bonus' => 0,
                            'carry_forward' => 0,
                            'position' => Customer::POSITION_RIGHT,
                            'created_at' => $now,
                            'updated_at' => $now,

                        ]
                    );

                    SalesBonusDetail::insert($sales_bonus_detail);
                    $this->updateEarning($user->id, $left_points, 0);
                    unset($sales_bonus_detail);
                }
//            }
        }
        if(!empty($update_is_paired_ids)){
            Customer::whereIn('user_id', $update_is_paired_ids)->update(['is_paired'=> 1]);
        }

        unset($update_is_paired_ids);
    }


    /**
     * @param $position
     * @param int $iteration
     * @return array
     * $position of root's Child
     * $iteration always pass as Zero
     */

     public function getAllChilds($position, $iteration = 0) {

        if (empty(self::$customers)) {
            self::$customers = Customer::all();
        }

        $leaves = self::$customers->whereIn('parent_id', self::$user_id);


        if ($iteration == 0) {
            $neglect_child = self::$customers->whereIn('parent_id', self::$user_id)->where('position', '!=', $position)->first();

            if (! empty($neglect_child))
                $leaves = $leaves->where('user_id', '!=', $neglect_child->user_id);

            $iteration++;
        }

        $leaves = $leaves->pluck('user_id')->toArray();

        if (!empty($leaves)) {
            self::$user_id = $leaves;
            self::$childs = array_merge(self::$childs, $leaves);
            $this->getAllChilds($position, $iteration);
        } else {
            self::$childs = self::$customers->where('is_paired', Customer::NOT_PAIRED)->whereIn('user_id', self::$childs)->pluck('user_id')->toArray();
        }

        return self::$childs;
     }

    /**
     * @param $user_id
     * @param $bv
     * @param $carry_forward
     * @return mixed
     */
     private function updateEarning($user_id, $bv, $carry_forward) {
         $earnings = Earning::firstOrNew(['user_id' => $user_id]);
         $earnings->sales_bonus = (float) $earnings->sales_bonus + (float) $bv;
         $earnings->carry_forward = $carry_forward;
         $earnings->earned = (float) $earnings->team_bonus + (float) $earnings->sales_bonus;
         $earnings->save();

         return $earnings;
     }


    /**
     * @param $position
     * @param $bv
     * @param $user_id
     */
     private function noWeakerSideFound($position, $bv, $user_id) {
         // use this bv as carry forward because binary tree, second leg not found.
         $now = now()->toDateTimeString();
         SalesBonusDetail::insert(array(
             [
                 'user_id' => $user_id,
                 'sales_bonus' => 0,
                 'carry_forward' => $bv,
                 'position' => $position,
                 'created_at' => $now,
                 'updated_at' => $now,
             ],
             [
                 'user_id' => $user_id,
                 'sales_bonus' => 0,
                 'carry_forward' => 0,
                 'position' => $position == Customer::POSITION_RIGHT ? Customer::POSITION_LEFT : Customer::POSITION_RIGHT,
                 'created_at' => $now,
                 'updated_at' => $now,

             ]
         ));
         $this->updateEarning($user_id, 0, $bv);
         //Log::notice('no weaker side found for user id#' .$user_id);
     }

    public function getUplineIDs(int $parent_id){

        self::$upline = [];
        $this->getUpline($parent_id);
        return self::$upline;
    }

    private function getUpline($parent_id){

        array_push(self::$upline, $parent_id);
        if (empty(self::$customers)) {
            self::$customers = Customer::all();
        }
        $parent = self::$customers->where('user_id', $parent_id)->first();
        if(!empty($parent)){

            if($parent->parent_id == 2){
                return false;
            }
            return $this->getUpline($parent->parent_id);
        }
    }

    public function fail($exception = null)
    {
        Log::debug($exception, ['File'=> __FILE__]);
    }

}
