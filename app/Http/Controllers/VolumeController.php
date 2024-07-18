<?php

namespace App\Http\Controllers;
use App\Models\Volume;
use Illuminate\Http\Request;

class VolumeController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $volume = Volume::all();
        return view('admin.volume', compact('volume'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        
        // Validate the request data
        $request->validate([
            'volume' => 'required|string',
        ]);

        // Create a new portfolio item
        $volume = new Volume([
            'name' => $request->input('volume'),
        ]);

        // Save the portfolio item to the database
        $volume->save();

        // Redirect to the portfolio index page with a success message
        return 'Muvaffaqiyatli Saqlandi!';
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $volum = Volume::findOrFail($id);
        $volume = Volume::all();
        return view('admin.volume_edit', compact('volume', 'volum'));
    }
    

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        $request->validate([
            'volume' => 'required|string',
            'vid' => 'required|integer',
        ]);
        $vid = $request->input('vid');
        $volume = Volume::findOrFail($vid);
        $volume->name = $request->input('volume');
        $volume->save();

        return 'Muvaffaqiyatli Saqlandi!';
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        $selectedIds = $request->input('selectedIds', []);
    
        if (empty($selectedIds)) {
            return redirect()->back()->with('error', 'Tanlangan elementlar topilmadi.');
        }
    
        try {
            // Use whereIn to find and delete all selected items
            Volume::whereIn('id', $selectedIds)->delete();            
            // If you want to return a response message, you can do so
            return response()->json('O`chirildi.');
        } catch (\Exception $e) {
            return response()->json($e->getMessage(), 500); // Handle any errors
        }
    }
}
