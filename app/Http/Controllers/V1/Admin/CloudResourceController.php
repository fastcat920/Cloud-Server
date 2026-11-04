<?php
namespace App\Http\Controllers\V1\Admin;
use App\Http\Controllers\Controller;
use App\Models\CloudAccount;
use App\Services\{CloudComputeService,CloudObjectService};
use Illuminate\Http\Request;
class CloudResourceController extends Controller
{
    private function input(Request $request,$rules=[]) {
        $data=$request->validate(array_merge(['account_id'=>'required|integer|exists:v2_cloud_account,id','region'=>'required|string|regex:/^[a-z0-9-]{2,50}$/','project'=>'nullable|string|max:100'],$rules));
        $account=CloudAccount::findOrFail($data['account_id']);if (!$account->enabled) abort(422,'云账号已停用');
        return [$account,$data];
    }
    private function execute(callable $callback) { try { return response(['data'=>$callback()]); } catch (\RuntimeException $e) { abort(422,$e->getMessage()); } }
    private function audit($account,$operation,$resource) {
        \App\Support\FileStore\Store::transaction(function () use ($account,$operation,$resource) {
            $rows=\App\Support\FileStore\Store::rows('cloud_operations');
            $saved=\App\Support\FileStore\Store::put('cloud_operations',['actor_id'=>request()->input('user.id'),'account_id'=>$account->id,'account_name'=>$account->name,'operation'=>$operation,'resource'=>$resource,'created_at'=>time()]);
            if (count($rows)>=1000) foreach (array_slice($rows,0,count($rows)-999,true) as $id=>$row) \App\Support\FileStore\Store::remove('cloud_operations',$id);
        });
    }
    public function regions(Request $request,CloudComputeService $service) {
        $data=$request->validate(['account_id'=>'required|integer|exists:v2_cloud_account,id']);$account=CloudAccount::findOrFail($data['account_id']);
        return $this->execute(fn()=>$service->regions($account));
    }
    public function instances(Request $request,CloudComputeService $service) {
        [$account,$data]=$this->input($request,['page'=>'nullable|integer|min:1|max:100000']);
        return $this->execute(fn()=>$service->instances($account,$data['region'],$data['page']??1,$data['project']??null));
    }
    public function operate(Request $request,CloudComputeService $service) {
        [$account,$data]=$this->input($request,['instance_id'=>'required|string|max:100|regex:/^[a-zA-Z0-9-]+$/','operation'=>'required|in:start,stop,reboot','confirmation'=>'required|same:instance_id']);
        return $this->execute(function () use ($account,$data,$service) {
            $result=$service->operate($account,$data['region'],$data['instance_id'],$data['operation'],$data['project']??null);
            $this->audit($account,$data['operation'],$data['region'].'/'.$data['instance_id']);
            return ['submitted'=>true,'request_id'=>$result['RequestId']??null];
        });
    }
    public function buckets(Request $request,CloudObjectService $service) {
        [$account,$data]=$this->input($request,['marker'=>'nullable|string|max:1024']);
        return $this->execute(fn()=>$service->buckets($account,$data['region'],$data['marker']??''));
    }
    private function objectInput(Request $request,$extra=[]) { return $this->input($request,array_merge(['bucket'=>'required|string|regex:/^[a-z0-9][a-z0-9.-]{1,250}$/','key'=>'required|string|max:1024'],$extra)); }
    public function objects(Request $request,CloudObjectService $service) {
        [$account,$data]=$this->input($request,['bucket'=>'required|string|regex:/^[a-z0-9][a-z0-9.-]{1,250}$/','prefix'=>'nullable|string|max:1024','marker'=>'nullable|string|max:1024']);
        return $this->execute(fn()=>$service->objects($account,$data['region'],$data['bucket'],$data['prefix']??'',$data['marker']??''));
    }
    public function upload(Request $request,CloudObjectService $service) {
        [$account,$data]=$this->objectInput($request,['file'=>'required|file|max:20480','confirmation'=>'required|same:key']);
        return $this->execute(function () use ($account,$data,$request,$service) {
            $service->request($account,'PUT',$data['region'],$data['bucket'],$data['key'],[],file_get_contents($request->file('file')->getRealPath()));
            $this->audit($account,'upload',$data['bucket'].'/'.$data['key']);return true;
        });
    }
    public function delete(Request $request,CloudObjectService $service) {
        [$account,$data]=$this->objectInput($request,['confirmation'=>'required|same:key']);
        return $this->execute(function () use ($account,$data,$service) {
            $service->request($account,'DELETE',$data['region'],$data['bucket'],$data['key']);
            $this->audit($account,'delete',$data['bucket'].'/'.$data['key']);return true;
        });
    }
    public function download(Request $request,CloudObjectService $service) {
        [$account,$data]=$this->objectInput($request);
        try { $response=$service->request($account,'GET',$data['region'],$data['bucket'],$data['key']); } catch (\RuntimeException $e) { abort(422,$e->getMessage()); }
        return response()->streamDownload(function () use ($response) { $body=$response->getBody();while (!$body->eof()) echo $body->read(65536); },basename($data['key']),['Content-Type'=>'application/octet-stream','Cache-Control'=>'no-store']);
    }
    public function operations(Request $request) {
        $data=$request->validate(['account_id'=>'required|integer|exists:v2_cloud_account,id']);
        $rows=array_values(array_filter(\App\Support\FileStore\Store::rows('cloud_operations'),fn($r)=>(int)$r['account_id']===(int)$data['account_id']));
        return response(['data'=>array_slice(array_reverse($rows),0,100)]);
    }
}
