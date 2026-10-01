<?php

namespace App\Http\Controllers;

use App\Support\SeoPages;

/** Pages de contenu du site public (solutions, guides, métiers, pays) décrites dans config/seo.php. */
class SeoPageController extends Controller
{
    public function show(SeoPages $pages, string $key)
    {
        return view('seo.page', ['p' => $pages->page($key)]);
    }

    public function hub(SeoPages $pages)
    {
        return view('seo.hub', ['hub' => $pages->hub()]);
    }
}
