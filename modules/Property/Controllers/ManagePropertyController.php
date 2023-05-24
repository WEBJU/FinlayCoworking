<?php
namespace Modules\Property\Controllers;

use Modules\FrontendController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Property\Models\Property;
use Modules\Location\Models\Location;
use Modules\Core\Models\Attributes;
use Modules\Booking\Models\Booking;
use Modules\Property\Models\PropertyTerm;
use Modules\Property\Models\PropertyTranslation;
use Modules\Property\Models\PropertyCategory;
use Illuminate\Support\Facades\DB;
use Modules\Vendor\Models\BcContactObject;

class ManagePropertyController extends FrontendController
{
    protected $propertyClass;
    protected $propertyTranslationClass;
    protected $propertyTermClass;
    protected $attributesClass;
    protected $locationClass;
    protected $propertyCategoryClass;
    protected $bookingClass;

    public function __construct()
    {
        parent::__construct();
        $this->propertyClass = Property::class;
        $this->propertyTranslationClass = PropertyTranslation::class;
        $this->propertyTermClass = PropertyTerm::class;
        $this->attributesClass = Attributes::class;
        $this->locationClass = Location::class;
        $this->propertyCategoryClass = PropertyCategory::class;
        $this->bookingClass = Booking::class;
    }
    public function callAction($method, $parameters)
    {
        if(!Property::isEnable())
        {
            return redirect('/');
        }
        return parent::callAction($method, $parameters); // TODO: Change the autogenerated stub
    }

    public function manageProperty(Request $request)
    {
        $this->checkPermission('property_view');
        $user_id = Auth::id();
        $rows = $this->propertyClass::query()->select("bc_properties.*")->where("bc_properties.create_user", $user_id);

        if (!empty($search = $request->input("s"))) {
            $rows->where(function($query) use ($search) {
                $query->where('bc_properties.title', 'LIKE', '%' . $search . '%');
                $query->orWhere('bc_properties.content', 'LIKE', '%' . $search . '%');
            });

            if( setting_item('site_enable_multi_lang') && setting_item('site_locale') != app_get_locale() ){
                $rows->leftJoin('bc_property_translations', function ($join) use ($search) {
                    $join->on('bc_properties.id', '=', 'bc_property_translations.origin_id');
                });
                $rows->orWhere(function($query) use ($search) {
                    $query->where('bc_property_translations.title', 'LIKE', '%' . $search . '%');
                    $query->orWhere('bc_property_translations.content', 'LIKE', '%' . $search . '%');
                });
            }
        }

        if(!empty($status = $request->input("status"))){
            $rows->where('status', $status);
        }

        if(!empty($category_id = $request->input("category_id")) && $category_id > 0){
            $rows->join('bc_property_category_relationships as pcr', 'pcr.property_id', "bc_properties.id")
                 ->where('pcr.category_id', $category_id);
        }

        if (!empty($filterSelect = $request->input("select_filter"))) {
            if ($filterSelect == 'recent') {
                $rows->orderBy('bc_properties.id','desc');
            }

            if ($filterSelect == 'old') {
                $rows->orderBy('bc_properties.id','asc');
            }

            if ($filterSelect == 'featured') {
                $rows->where('bc_properties.is_featured','=', 1);
            }
        } else {
            $rows->orderBy('bc_properties.id','desc');
        }

        $data = [
            'rows' => $rows->with('categories')->paginate(5),
            'breadcrumbs'        => [
                [
                    'name' => __('Manage Properties'),
                    'url'  => route('property.vendor.index')
                ],
                [
                    'name'  => __('All'),
                    'class' => 'active'
                ],
            ],
            'page_title'         => __("Manage Properties"),
        ];
        return view('Property::frontend.manageProperty.index', $data);
    }


    public function createProperty(Request $request)
    {
        $this->checkPermission('property_create');
        $row = new $this->propertyClass();
        $data = [
            'row'           => $row,
            'translation' => new $this->propertyTranslationClass(),
            'property_category'    => $this->propertyCategoryClass::where('status', 'publish')->get()->toTree(),
            'property_location' => $this->locationClass::where("status","publish")->get()->toTree(),
            'attributes'    => $this->attributesClass::where('service', 'property')->get(),
            'breadcrumbs'        => [
                [
                    'name' => __('Manage Properties'),
                    'url'  => route('property.vendor.index')
                ],
                [
                    'name'  => __('Create'),
                    'class' => 'active'
                ],
            ],
            'page_title'         => __("Create Properties"),
        ];
        return view('Property::frontend.manageProperty.detail', $data);
    }


    public function store( Request $request, $id ){

        if($id>0){
            $this->checkPermission('property_update');
            $row = $this->propertyClass::find($id);
            if (empty($row)) {
                return redirect(route('property.vendor.index'));
            }

            if($row->create_user != Auth::id() and !$this->hasPermission('property_manage_others'))
            {
                return redirect(route('property.vendor.index'));
            }
        }else{
            $this->checkPermission('property_create');
            $row = new $this->propertyClass();
            $row->status = "publish";
            if(setting_item("property_vendor_create_service_must_approved_by_admin", 0)){
                $row->status = "pending";
            }
        }
        $dataKeys = [
            'title',
            'content',
            'price',
            'is_instant',
            'video',
            'faqs',
            'image_id',
            'banner_image_id',
            'gallery',
            'bed',
            'bathroom',
            'square',
            'location_id',
            'address',
            'map_lat',
            'map_lng',
            'map_zoom',
            'default_state',
            'price',
            'sale_price',
            'max_guests',
            'enable_extra_price',
            'extra_price',
            'is_featured',
            'default_state',
            'deposit',
            'pool_size',
            'additional_zoom',
            'remodal_year',
            'amenities',
            'equipment',
            'property_type',
            'is_sold',
            'enable_open_hours',
            'open_hours',
            'property_logo',
            'price_range',
            'banner_images',
            'email',
            'phone',
            'website',
            'price_from',
            'socials',
        ];
        if($this->hasPermission('property_manage_others')){
            $dataKeys[] = 'create_user';
        }
        $row->fillByAttr($dataKeys,$request->input());

        if($request->input('slug')){
            $row->slug = $request->input('slug');
        }


        $res = $row->saveOriginOrTranslation($request->input('lang'),true);

        $row->categories()->sync($request->input('categories') ?? []);

        if ($res) {
            if(!$request->input('lang') or is_default_lang($request->input('lang'))) {
                $this->saveTerms($row, $request);
            }

            if($id > 0 ){
                return back()->with('success',  __('Property updated') );
            }else{
                return redirect(route('property.vendor.edit',['id'=>$row->id]))->with('success', __('Property created') );
            }
        }
    }

    public function saveTerms($row, $request)
    {
        if (empty($request->input('terms'))) {
            $this->propertyTermClass::where('target_id', $row->id)->delete();
        } else {
            $term_ids = $request->input('terms');
            foreach ($term_ids as $term_id) {
                $this->propertyTermClass::firstOrCreate([
                    'term_id' => $term_id,
                    'target_id' => $row->id
                ]);
            }
            $this->propertyTermClass::where('target_id', $row->id)->whereNotIn('term_id', $term_ids)->delete();
        }
    }

    public function editProperty(Request $request, $id)
    {
        $this->checkPermission('property_update');
        $user_id = Auth::id();
        $row = $this->propertyClass::where("create_user", $user_id);
        $row = $row->find($id);
        if (empty($row)) {
            return redirect(route('property.vendor.index'))->with('warning', __('Property not found!'));
        }
        $translation = $row->translateOrOrigin($request->query('lang'));
        $data = [
            'translation'    => $translation,
            'row'           => $row,
            'property_category'    => $this->propertyCategoryClass::where('status', 'publish')->get()->toTree(),
            'property_location' => $this->locationClass::where("status","publish")->get()->toTree(),
            'attributes'    => $this->attributesClass::where('service', 'property')->get(),
            "selected_terms" => $row->terms->pluck('term_id'),
            'breadcrumbs'        => [
                [
                    'name' => __('Manage Properties'),
                    'url'  => route('property.vendor.index')
                ],
                [
                    'name'  => __('Edit'),
                    'class' => 'active'
                ],
            ],
            'page_title'         => __("Edit Properties"),
        ];
        return view('Property::frontend.manageProperty.detail', $data);
    }

    public function deleteProperty($id)
    {
        $this->checkPermission('property_delete');
        $user_id = Auth::id();
        $query = $this->propertyClass::where("create_user", $user_id)->where("id", $id)->first();
        if(!empty($query)){
            $query->delete();
        }
        return redirect(route('property.vendor.index'))->with('success', __('Delete property success!'));
    }

    public function bulkEditProperty($id , Request $request){
        $this->checkPermission('property_update');
        $action = $request->input('action');
        $user_id = Auth::id();
        $query = $this->propertyClass::where("create_user", $user_id)->where("id", $id)->first();
        if (empty($id)) {
            return redirect()->back()->with('error', __('No item!'));
        }
        if (empty($action)) {
            return redirect()->back()->with('error', __('Please select an action!'));
        }
        if(empty($query)){
            return redirect()->back()->with('error', __('Not Found'));
        }
        switch ($action){
            case "make-hide":
                $query->status = "draft";
                break;
            case "make-publish":
                $query->status = "publish";
                break;
        }
        $query->save();
        return redirect()->back()->with('success', __('Update success!'));
    }

    public function bookingReport(Request $request)
    {
        $data = [
            'bookings' => $this->bookingClass::getBookingHistory($request->input('status'), false , Auth::id() , 'property'),
            'statues'  => config('booking.statuses'),
            'breadcrumbs'        => [
                [
                    'name' => __('Manage Property'),
                    'url'  => route('property.vendor.index')
                ],
                [
                    'name' => __('Booking Report'),
                    'class'  => 'active'
                ]
            ],
            'page_title'         => __("Booking Report"),
        ];
        return view('Property::frontend.manageProperty.bookingReport', $data);
    }

    public function bookingReportBulkEdit($booking_id , Request $request){
        $status = $request->input('status');
        if (!empty(setting_item("property_allow_vendor_can_change_their_booking_status")) and !empty($status) and !empty($booking_id)) {
            $query = $this->bookingClass::where("id", $booking_id);
            $query->where("vendor_id", Auth::id());
            $item = $query->first();
            if(!empty($item)){
                $item->status = $status;
                $item->save();
                $item->sendStatusUpdatedEmails();
                return redirect()->back()->with('success', __('Update success'));
            }
            return redirect()->back()->with('error', __('Booking not found!'));
        }
        return redirect()->back()->with('error', __('Update fail!'));
    }

	public function cloneProperty(Request $request,$id){
		$this->checkPermission('property_update');
		$user_id = Auth::id();
		$row = $this->propertyClass::where("create_user", $user_id);
		$row = $row->find($id);
		if (empty($row)) {
			return redirect(route('property.vendor.index'))->with('warning', __('Property not found!'));
		}
		try{
			$clone = $row->replicate();
			$clone->status  = 'draft';
			$clone->push();
			if(!empty($row->terms)){
				foreach ($row->terms as $term){
					$e= $term->replicate();
					if($e->push()){
						$clone->terms()->save($e);

					}
				}
			}
			if(!empty($row->meta)){
				$e= $row->meta->replicate();
				if($e->push()){
					$clone->meta()->save($e);

				}
			}
			if(!empty($row->translations)){
				foreach ($row->translations as $translation){
					$e = $translation->replicate();
					$e->origin_id = $clone->id;
					if($e->push()){
						$clone->translations()->save($e);
					}
				}
			}

			return redirect()->back()->with('success',__('Property clone was successful'));
		}catch (\Exception $exception){
			$clone->delete();
			return redirect()->back()->with('warning',__($exception->getMessage()));
		}
	}

    public function showContact(Request $request) {
        $rows = BcContactObject::where('object_model', '=', 'property')->where('vendor_id',Auth::id())->paginate(20);
        if (count($rows) > 0) {
            foreach($rows as $row) {
                $row->nameProperty = $this->propertyClass::where('id', $row->object_id)->first()->title;
                $row->nameVendor = DB::table('users')->select(DB::raw('CONCAT(first_name, " ", last_name) AS name'))->where('id', $row->vendor_id)->first()->name;
            }
        }
        $data = [
            'rows'        => $rows,
            'breadcrumbs' => [
                [
                    'name' => __('Manage Property'),
                    'url'  => route('property.vendor.index')
                ],
                [
                    'name'  => __('Contact'),
                    'class' => 'active'
                ],
            ],
            'page_title'  => __("Contact property"),
        ];
        return view('Property::frontend.manageProperty.contact', $data);
    }

}
