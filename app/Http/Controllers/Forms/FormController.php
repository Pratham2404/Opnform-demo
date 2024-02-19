<?php

namespace App\Http\Controllers\Forms;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFormRequest;
use App\Http\Requests\UpdateFormRequest;
use App\Http\Requests\UploadAssetRequest;
use App\Http\Resources\FormResource;
use App\Models\Forms\Form;
use App\Models\Store;
use App\Models\Workspace;
use App\Service\Forms\FormCleaner;
use App\Service\Storage\StorageFileNameParser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FormController extends Controller
{
    const ASSETS_UPLOAD_PATH = 'assets/forms';

    private FormCleaner $formCleaner;

    public function __construct()
    {
        $this->middleware('auth');
        $this->formCleaner = new FormCleaner();
    }

    public function index($workspaceId)
    {
        $workspace = Workspace::findOrFail($workspaceId);
        $this->authorize('view', $workspace);
        $this->authorize('viewAny', Form::class);

        $workspaceIsPro = $workspace->is_pro;
        $forms = $workspace->forms()
            ->orderByDesc('updated_at')
            ->paginate(10)->through(function (Form $form) use ($workspace, $workspaceIsPro){

            // Add attributes for faster loading
            $form->extra = (object) [
                'loadedWorkspace' => $workspace,
                'workspaceIsPro' => $workspaceIsPro,
                'userIsOwner' => true,
                'cleanings' => $this->formCleaner
                    ->processForm(request(), $form)
                    ->simulateCleaning($workspace)
                    ->getPerformedCleanings()
            ];

            return $form;
        });
        return FormResource::collection($forms);
    }

    /**
     * Return all user forms, used for zapier
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function indexAll()
    {
        $forms = collect();
        foreach (Auth::user()->workspaces as $workspace) {
            $this->authorize('view', $workspace);
            $this->authorize('viewAny', Form::class);

            $workspaceIsPro = $workspace->is_pro;
            $newForms = $workspace->forms()->get()->map(function (Form $form) use ($workspace, $workspaceIsPro){
                // Add attributes for faster loading
                $form->extra = (object) [
                    'loadedWorkspace' => $workspace,
                    'workspaceIsPro' => $workspaceIsPro,
                    'userIsOwner' => true,
                ];
                return $form;
            });

            $forms = $forms->merge($newForms);
        }
        return FormResource::collection($forms);
    }

    public function store(StoreFormRequest $request)
    {
        $this->authorize('create', Form::class);

        $workspace = Workspace::findOrFail($request->get('workspace_id'));
        $this->authorize('view', $workspace);

        $formData = $this->formCleaner
            ->processRequest($request)
            ->simulateCleaning($workspace)
            ->getData();

            for ($i = 0; $i < count($formData['properties']); $i++) {
                if(isset($formData['properties'][$i]['is_rating']) && $formData['properties'][$i]['is_rating']){
                    $formData['properties'][$i]['rating_max_value']=5;
                }
                $property = &$formData['properties'][$i]; // Using reference to update the original array
                // Check if auto_fill_data is set and not equal to 'static'
                if (isset($property['auto_fill_data']) && $property['auto_fill_data'] == 'store') {
                    // Assuming your query and logic are correct, and you want to fill options for multi_select type
                    if ($property['type'] === 'multi_select' ||$property['type'] === 'select') {
                        $users = Store::select('name', 'ws_alternate_code')->get();
                        $options = [];
                        foreach ($users as $user) {
                            $options[] = ["name" => $user->name, "value" => $user->ws_alternate_code];
                        }
                        $property[$property['type']]['options'] = $options;
                    }
                    // Add more conditions for other types if needed
                }
            }
        // for ($i = 0; $i < count($formData['properties']); $i++) {
        //     if(isset($formData['properties'][$i]['is_rating']) && $formData['properties'][$i]['is_rating']){
        //         // dd($formData['properties'][$i]['is_rating']);
        //         $formData['properties'][$i]['rating_max_value'] = 5;
        //     }
        //     if(isset($formData['properties'][$i]['is_scale']) && $formData['properties'][$i]['is_scale']){
        //         // dd($formData['properties'][$i]['is_rating']);
        //         $formData['properties'][$i]['scale_max_value'] = 5;
        //     }
        // }
            

        // $form = $request->all();
        // $properties = $form['properties'];
        // $d = [];
        // // dd($properties[0]['select']['options']);
        // $i = 0;
        // foreach ($properties as $property) {

        

        $form = Form::create(array_merge($formData, [
            'creator_id' => $request->user()->id
        ]));

        return $this->success([
            'message' => $this->formCleaner->hasCleaned() ? 'Form successfully created, but the Pro features you used will be disabled when sharing your form:' : 'Form created.' . ($form->visibility == 'draft' ? ' But other people won\'t be able to see the form since it\'s currently in draft mode' : ''),
            'form' => (new FormResource($form))->setCleanings($this->formCleaner->getPerformedCleanings()),
            'users_first_form' => $request->user()->forms()->count() == 1
        ]);
    }

    public function update(UpdateFormRequest $request, string $id)
    {
        $form = Form::findOrFail($id);
        $this->authorize('update', $form);

        $formData = $this->formCleaner
            ->processRequest($request)
            ->simulateCleaning($form->workspace)
            ->getData();

        // Set Removed Properties
        $formData['removed_properties'] = array_merge($form->removed_properties, collect($form->properties)->filter(function ($field) use ($formData) {
            return (!Str::of($field['type'])->startsWith('nf-') && !in_array($field['id'], collect($formData['properties'])->pluck("id")->toArray()));
        })->toArray());

        $form->update($formData);

        return $this->success([
            'message' => $this->formCleaner->hasCleaned() ? 'Form successfully updated, but the Pro features you used will be disabled when sharing your form:' : 'Form updated.' . ($form->visibility == 'draft' ? ' But other people won\'t be able to see the form since it\'s currently in draft mode' : ''),
            'form' => (new FormResource($form))->setCleanings($this->formCleaner->getPerformedCleanings()),
        ]);
    }

    public function destroy($id)
    {
        $form = Form::findOrFail($id);
        $this->authorize('delete', $form);

        $form->delete();
        return $this->success([
            'message' => 'Form was deleted.'
        ]);
    }

    public function duplicate($id)
    {
        $form = Form::findOrFail($id);
        $this->authorize('update', $form);

        // Create copy
        $formCopy = $form->replicate();
        $formCopy->title = 'Copy of '.$formCopy->title;
        $formCopy->save();

        return $this->success([
            'message' => 'Form successfully duplicated.',
            'new_form' => new FormResource($formCopy)
        ]);
    }

    public function regenerateLink($id, $option)
    {
        $form = Form::findOrFail($id);
        $this->authorize('update', $form);

        if ( $option == 'slug') {
            $form->generateSlug();
        } elseif ($option == 'uuid') {
            $form->slug = Str::uuid();
        }
        $form->save();

        return $this->success([
            'message' => 'Form url successfully updated. Your new form url now is: '.$form->share_url.'.',
            'form' => new FormResource($form)
        ]);
    }

    /**
     * Upload a form asset
     */
    public function uploadAsset(UploadAssetRequest $request)
    {
        $this->authorize('viewAny', Form::class);

        $fileNameParser = StorageFileNameParser::parse($request->url);

        // Make sure we retrieve the file in tmp storage, move it to persistent
        $fileName = PublicFormController::TMP_FILE_UPLOAD_PATH.'/'.$fileNameParser->uuid;;
        if (!Storage::exists($fileName)) {
            // File not found, we skip
            return null;
        }
        $newPath = self::ASSETS_UPLOAD_PATH.'/'.$fileNameParser->getMovedFileName();
        Storage::move($fileName, $newPath);

        return $this->success([
            'message' => 'File uploaded.',
            'url' => route("forms.assets.show", [$fileNameParser->getMovedFileName()])
        ]);
    }

    /**
     * File uploads retrieval
     */
    public function viewFile($id, $fileName)
    {
        $form = Form::findOrFail($id);
        $this->authorize('view', $form);

        $path = Str::of(PublicFormController::FILE_UPLOAD_PATH)->replace('?', $form->id).'/'.$fileName;
        if (!Storage::exists($path)) {
            return $this->error([
                'message' => 'File not found.'
            ]);
        }

        return redirect()->to(Storage::temporaryUrl($path, now()->addMinutes(5)));
    }
}