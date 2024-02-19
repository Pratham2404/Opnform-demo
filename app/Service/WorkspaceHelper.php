<?php


namespace App\Service;


use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class WorkspaceHelper
{

    protected $workspacee;

    public function __construct(public Workspace $workspace)
    {
        $this->workspacee = $workspace;
    }

    public function getRecords($relatedRecordIds = null)
    {
        return [];
    }
    public function getAllUsers($workspaceId){
        if($workspaceId==null){
            $workspaceId = $this->workspacee->id;
        }
        $users = DB::table("user_workspace")->where("workspace_id", $workspaceId)->get();
        return $users;
    }
}
