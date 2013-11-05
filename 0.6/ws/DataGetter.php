<?php

//define('MAX_TILE_AGE', 2592000);
define('MAX_TILE_AGE', 86400);

require_once('TileList.php');

class DataGetter
{
    protected $data, $kothic_gran, $wayMustMatch, $poiFilter, $wayFilter,
            $doWays, $doPolygons, $dbq;

    function __construct($kothic_gran=null, $dbdetails=null, $srid="900913")
    {
        $this->data = array();
        $this->data["features"] = array();
        $this->data["type"] = "FeatureCollection";
        $this->data["properties"]["copyright"] =
            "Map data OpenStreetMap Contributors, Open Database Licence; ".
            "contours Ordnance Survey, OS OpenData licence";
        $this->kothic_gran = $kothic_gran;
        $this->poiFilter=array();
        $this->wayFilter=array();
        $this->doWays=true;
        $this->doPolygons=true;
		$prefix = is_string($dbdetails) ? $dbdetails: "planet_osm";
        $this->dbq = is_object($dbdetails)&&get_class($dbdetails)=="DBDetails"? 
				$dbdetails:
                new DBDetails(
                    array("table"=>"${prefix}_point",
                            "col"=>"way"),
                    array("table"=>"${prefix}_line",
                            "col"=>"way"),
                    array("table"=>"${prefix}_polygon",
                            "col"=>"way"),
                    array("table"=>"contours",
                            "col"=>"way"),
                    array("table"=>"coastlines",
                            "col"=>"the_geom"),
                    array("col"=>"xy")
                            ); 
        $this->SRID = $srid;

		// 240913 if not in kothic mode we want the full ways, not just
		// the intersection with the bbox
		$this->dbq->setIntersection($this->kothic_gran !==null);

    }

    function setCopyright($copyright)
    {
        $this->data["properties"]["copyright"] =  $copyright;
    }

    function getData($options,$outProj=null)
    {
        $this->doGetData($options);
        if($outProj!==null)
            $this->reprojectData($outProj);
        return $this->data;
    }

    protected function doGetData($options)
    {
        $plyrs = isset($options["poi"]) ? explode(",", $options["poi"]):null;
        $wlyrs = isset($options["way"]) ? explode(",", $options["way"]):null;

        if(isset($options["poi"]))
            $this->getPOIData($plyrs);


        if(isset($options["way"]))
            $this->getWayData($wlyrs);

    }

    static function criteria($lyrs)
    {
        $qry="";
        if (count($lyrs) != 0)
        {
            $qry .= " AND (";
            for($i=0; $i<count($lyrs); $i++)
            {
                if($i!=0)
                    $qry.=" OR ";
                if ($lyrs[$i] == "natural")
                    $lyrs[$i] = "\"natural\"";
                $qry .= $lyrs[$i] . " <> '' ";
            }
            $qry .= ")";
        }


        return $qry;
    }

    function addPOIFilter($k,$allowedValues)
    {
        $this->poiFilter[$k]=$allowedValues;
    }

    function addWayFilter($k,$allowedValues)
    {
        $this->wayFilter[$k]=$allowedValues;
    }

    function includeWays($w)
    {
        $this->doWays=$w;
    }

    function includePolygons($p)
    {
        $this->doPolygons=$p;
    }

    function applyFilter($type)
    {
        $qry="";
        $filter = ($type=="poi") ? $this->poiFilter: $this->wayFilter;

        if(count($filter) > 0)
        {
            foreach($filter as $tag=>$valuelist)
            {
                $qry .= " AND (";
                $values = explode(",",$valuelist);
                $first=true;
                foreach($values as $value)
                {
                    if($first==false)
                        $qry .= " OR ";
                    else
                        $first=false;
                    $qry .= "$tag='$value'"; 
                }
                $qry .= ")";
            }
        }
        return $qry;
    }

    function getPOIData($plyrs)
    {

        //$pqry = $this->dbq->getPOIQuery();
        $pqry = $this->getPOIQuery();



        if($plyrs[0]!="all")
            $pqry .= DataGetter::criteria($plyrs);

        $pqry .= DataGetter::applyFilter("poi");



        $presult = pg_query($pqry);

        while($prow=pg_fetch_array($presult,null,PGSQL_ASSOC))
        {
            $feature=array();
            $feature["type"] = $this->kothic_gran===null?"Feature":"Point";
            $f= json_decode($prow["geojson"],true);
            $counteddata=array();

            foreach($prow as $k=>$v)    
                if($k!='way' && $k!='geojson' && $v!='')
                    $counteddata[$k]=htmlspecialchars
                        (str_replace("&","and",$v));

            $feature["properties"] = $counteddata;
            $feature["properties"]["featuretype"]=get_high_level
                ($feature["properties"]);
            if($this->kothic_gran===null)
            {
                $feature["geometry"]=array();
                $feature["geometry"]["coordinates"] = $f["coordinates"];
                $feature["geometry"]["type"] = $f["type"];
            }
            else
            {
                $feature["coordinates"]= $this->kothicAdjust($f);
            }
            $this->data["features"][] = $feature;
        }    
        pg_free_result($presult);
    }

    function getWayData($wlyrs)
    {
        $arr=array();
        if($this->doWays) 
            $arr[]="way";
        if($this->doPolygons)
            $arr[]= "polygon"; 

        foreach($arr as $table)
        {
            $wqry = $this->getWayQuery($table);

            if($wlyrs[0]!="all")
                $wqry .= DataGetter::criteria($wlyrs);
            $wqry .= DataGetter::applyFilter("way");
            $wresult = pg_query($wqry);
            $first=true;

            $excluded_col = ($table=="polygon") ?
                $this->dbq->polygonDetails["col"]:
                $this->dbq->wayDetails["col"];

            while($wrow=pg_fetch_array($wresult,null,PGSQL_ASSOC))
            {
                $feature=array();
                $f = json_decode($wrow['geojson'],true);
                $tags = array();
            
                // Replace ampersands with the word "and".
                foreach($wrow as $k=>$v)
                    if($k!=$excluded_col && $k!='geojson' && $v!='')
                        $tags[$k] = htmlspecialchars(str_replace("&","and",$v));

                $feature["properties"] = $tags;
                if($this->kothic_gran===null)
                {
                    $feature["type"] = "Feature"; 
                    $feature["geometry"]=array();
                    $feature["geometry"]["coordinates"] = $f["coordinates"];
                    $feature["geometry"]["type"] = $f["type"];
                    if(count($feature["geometry"]["coordinates"])>0)
                        $this->data["features"][] = $feature;
                }
                else
                {
                    $feature["coordinates"]= $this->kothicAdjust($f);
                    $feature["type"] = $f["type"];
                    if(count($feature["coordinates"])>0)
                        $this->data["features"][] = $feature;
                }
            }
            pg_free_result($wresult);
        }
    }

    function getPOIQuery()
    {
        return $this->dbq->getPOIQuery();
    }

    function getWayQuery($table)
    {
        return ($table=="polygon") ?
            $this->dbq->getPolygonQuery(): $this->dbq->getWayQuery();
    }

    function reprojectData($outProj)
    {
        for($f=0; $f<count($this->data["features"]); $f++)
        {
            switch($this->data["features"][$f]["geometry"]["type"])
            {
                case "Point":
                    $this->data["features"][$f]["geometry"]["coordinates"]=
                    reproject
                    ($this->data["features"][$f]["geometry"]["coordinates"][0],
                    $this->data["features"][$f]["geometry"]["coordinates"][1],
                    $this->SRID,$outProj);
                    break;

                case "LineString":
                    for($i=0; 
                        $i<count
                        ($this->data["features"][$f]
                        ["geometry"]["coordinates"]); $i++)
                    {
                        $this->data
                        ["features"][$f]["geometry"]["coordinates"][$i]=
                        reproject
                            ($this->data
                            ["features"][$f]["geometry"]["coordinates"]
                            [$i][0],
                            $this->data["features"][$f]["geometry"]
                            ["coordinates"][$i][1],
                            $this->SRID,$outProj);
                    }
                    break;

                case "MultiLineString":
                case "Polygon":
                    for($i=0; $i<count($this->data["features"][$f]["geometry"]
                        ["coordinates"]); $i++)
                    {
                        for($j=0;$j<count($this->data["features"][$f]
                            ["geometry"]["coordinates"][$i]);$j++)
                        {
                            $this->data
                            ["features"][$f]["geometry"]["coordinates"]
                                [$i][$j]=reproject
                                ($this->data["features"][$f]
                                ["geometry"]["coordinates"][$i][$j][0],
                                $this->data["features"][$f]
                                ["geometry"]["coordinates"][$i][$j][1],
                                $this->SRID,$outProj);
                        }
                    }
                    break;

                case "MultiPolygon":
                    for($i=0; $i<count($this->data["features"][$f]["geometry"]
                        ["coordinates"]); $i++)
                    {
                        for($j=0;$j<count($this->data["features"][$f]
                            ["geometry"]["coordinates"][$i]);$j++)
                        {
                            for($k=0; 
                                   $k<count($this->data["features"][$f]
                                ["geometry"]["coordinates"][$i][$j]);
                                $k++)
                            {
                                $this->data["features"][$f]
                                    ["geometry"]["coordinates"][$i][$j][$k]=
                                    reproject
                                        ($this->data["features"][$f]
                                    ["geometry"]["coordinates"][$i][$j][$k][0],
                                    $this->data["features"][$f]
                                    ["geometry"]["coordinates"][$i][$j][$k][1],
                                    $this->SRID,$outProj);
                            }
                        }
                    }
                    break;
            }
        }
    }
}

class NameSearch extends DataGetter
{
    protected $name;

    function __construct($name,$tbl_prefix="planet_osm")
    {
        parent::__construct(null,$tbl_prefix);
        $this->name=$name;
    }

    function getPOIQuery()
    {
        return parent::getPOIQuery()." AND name ILIKE '%".$this->name."%'";
    }

    function getWayQuery($table)
    {
        return parent::getWayQuery($table)." AND name ILIKE '%".
            $this->name."%'";
    }
}

class BboxGetter extends DataGetter
{
    private $bbox, $forceCache;

    function __construct($bbox,$kothic_gran=null,$dbdetails=null,$srid="900913")
    {
        parent::__construct($kothic_gran,$dbdetails,$srid);
        $this->bbox = $bbox;
        $this->geomtxt = $this->mkgeom();
        $this->geomtxt2 = $this->mkgeom2();
		$this->forceCache = false;
    }

	function setForceCache($fc)
	{
		$this->forceCache = $fc;
	}

    function getData($options, $contourCache=null, $cache=null, $outProj=null,
						$x=null, $y=null, $z=null)
    {

		// Only cache if all was requested and we're in kothic mode 
		$all = isset($options["coastline"]) && $options["coastline"]
			&& isset($options["poi"]) && $options["poi"]=="all"
			&& isset($options["way"]) && $options["way"]=="all"
			&& $this->kothic_gran;

		
		if($this->forceCache)
		{
			$this->getDataFromDB($options);
			$this->cacheData($cache);	
		}
		elseif($cache!==null && $all)
		{
			$result = $this->getCachedData($cache);
			if($result===false)
			{
				$this->getDataFromDB($options);
				$this->cacheData($cache);	
			}
			/* if cached tile over 30 days old, add to tilelist */
			elseif(time() - $result > MAX_TILE_AGE && $z >= 11)
			{
				$tileList = new TileList();
				$tileList->addTile($x, $y, $z);
			}
		}
		else
		{
			$this->getDataFromDB($options);
		}

        if(isset($options["contour"]) && $options["contour"])
            $this->getContourData($contourCache);

        if($outProj!==null)
            $this->reprojectData($outProj);

        return $this->data;
    }

	function getDataFromDB($options)
	{
		
        parent::doGetData($options);
        
        if(isset($options["coastline"]) && $options["coastline"])
            $this->getCoastlineData();
            

        if( (isset($options["ann"]) && $options["ann"]) ||
         (isset($options["annotation"]) && $options["annotation"]) ) 
        {
            $this->getAnnotationData();
        }
		elseif(isset($options["overlay"]))
		{
			$this->getOverlayData($options["overlay"]);
		}
	}

	function cacheData($cache)
	{
		file_put_contents($cache,json_encode($this->data["features"]));
	}

	function getCachedData($cache)
	{
        if($this->kothic_gran!==null && $cache!==null)
        {
            if(!file_exists($cache))
            {
				return false;
            }
            else
            {
                $txt=file_get_contents($cache);
                $this->data["features"]=json_decode($txt,true);
				return filemtime($cache); 
            }
        }
		return false;
	}

    function getContourData($contourCache=null)
    {
        if($this->kothic_gran!==null && $contourCache!==null)
        {
            if(!file_exists($contourCache))
            {
                $contourData=$this->doGetContourData();
                file_put_contents($contourCache,json_encode($contourData));
            }
            else
            {
                $txt=file_get_contents($contourCache);
                $contourData=json_decode($txt,true);
            }
        }
        else
        {
            $contourData=$this->doGetContourData();
        }
        $this->data["features"] = array_merge($this->data["features"], 
            $contourData);
    }


    function doGetContourData()
    {
        $features=array();
        $q=$this->dbq->getContourQuery($this->geomtxt);
        if($q===null)
            return;
        $result=pg_query($q);
        while($row=pg_fetch_array($result,null,PGSQL_ASSOC))
        {
            $feature=array();
            $f = json_decode($row['geojson'],true);
            $tags = array();
            $feature["properties"] = array();
            $feature["properties"]["contour"]=$row["height"];
            if($this->kothic_gran===null)
            {
                $feature["type"]="Feature";
                $feature["geometry"]=array();
                $feature["geometry"]["coordinates"] = $f["coordinates"];
                $feature["geometry"]["type"] = $f["type"];
                if(count($feature["geometry"]["coordinates"])>0)
                    $features[] = $feature;
            }
            else
            {
                $feature["coordinates"] = $this->kothicAdjust($f);
                $feature["type"] = $f["type"];
                if(count($feature["coordinates"])>0)
                    $features[] = $feature;
            }
        }
		pg_free_result($result);
        return $features;
    }

    function getCoastlineData()
    {
        if($this->kothic_gran!==null)
        {
            $factor = $this->kothic_gran / ($this->bbox[2]-$this->bbox[0]);
        }
        $q=$this->dbq->getCoastlineQuery($this->geomtxt);
        if($q===null)
            return;
        $result=pg_query($q);
        while($row=pg_fetch_array($result,null,PGSQL_ASSOC))
        {
            $feature=array();
            $f = json_decode($row['geojson'],true);
            $tags = array();
            $feature["properties"] = array();
            $feature["properties"]["natural"] = "land"; 
            if($this->kothic_gran===null)
            {
                $feature["type"]="Feature";
                $feature["geometry"]=array();
                $feature["geometry"]["coordinates"] = $f["coordinates"];
                $feature["geometry"]["type"] = $f["type"];
                if(count($feature["geometry"]["coordinates"])>0)
                    $this->data["features"][] = $feature;
            }
            else
            {
                $feature["coordinates"] =$this->kothicAdjust($f);
                $feature["type"] = $f["type"];
                if(count($feature["coordinates"])>0)
                    $this->data["features"][] = $feature;
            }
        } 
		pg_free_result($result);
    }
    
    function getAnnotationData()
    {
		self::getOverlayData("annotation");
	}

	function getOverlayData($type)
	{
        $pqry = $this->dbq->getOverlayQuery($this->geomtxt, $type); 
        if($pqry===null)
            return;

        $presult = pg_query($pqry);

        while($prow=pg_fetch_array($presult,null,PGSQL_ASSOC))
        {
            $feature=array();
            $feature["type"] = "Feature";
            $f= json_decode($prow["geojson"],true);
            $counteddata=array();
            foreach($prow as $k=>$v)    
                if($k!='authorised' && $k!='geojson' && 
                    $k!=$this->dbq->overlayDetails["col"] && $v!='')
                    $counteddata[$k]=$v;
            $feature["properties"] = $counteddata;
            $feature["properties"][$type] = "yes";
            $feature["geometry"]=array();
            $feature["geometry"]["coordinates"] = $f["coordinates"];
            $feature["geometry"]["type"] = $f["type"];
            $this->data["features"][] = $feature;
        }    
        pg_free_result($presult);
    }

    function mkgeom()
    {
        $bbox=$this->bbox;
        $g="GeomFromText('POLYGON(($bbox[0] $bbox[1],$bbox[2] $bbox[1], ".
            "$bbox[2] $bbox[3],$bbox[0] $bbox[3],$bbox[0] $bbox[1]))',".
            $this->SRID.")";
        return $g; 
    }

    function mkgeom2()
    {
        $bbox=$this->bbox;
        $w = $this->bbox[2] - $this->bbox[0];
        $h = $this->bbox[3] - $this->bbox[1];

        $bbox[0] = $bbox[0] - $w*0.2; 
        $bbox[2] = $bbox[2] + $w*0.2; 
        $bbox[1] = $bbox[1] - $h*0.2; 
        $bbox[3] = $bbox[3] + $h*0.2;
        $g="GeomFromText('POLYGON(($bbox[0] $bbox[1],$bbox[2] $bbox[1], ".
            "$bbox[2] $bbox[3],$bbox[0] $bbox[3],$bbox[0] $bbox[1]))',".
            $this->SRID.")";
        return $g; 
    }

    function kothicAdjust($f)
    {
        $factor = $this->kothic_gran / ($this->bbox[2]-$this->bbox[0]);
        $coords=array();
        switch($f["type"])
        {
            case "Point":
                $x =  (int)    
                    (($f["coordinates"][0] - $this->bbox[0]) * $factor);
                $y =     (int)
                    (($f["coordinates"][1] - $this->bbox[1]) * $factor);
            $coords = array($x,$y);
            break;

            case "LineString":
                for($i=0; $i<count($f["coordinates"]); $i++)
                {
                    $x =  (int)    
                    round(($f["coordinates"][$i][0]-$this->bbox[0]) * $factor);
                    $y =     (int)
                    round(($f["coordinates"][$i][1]-$this->bbox[1]) * $factor);
                    // coords of (0,0) seem to  screw up rendering
                    $x=($x==0)?1:$x;
                    $y=($y==0)?1:$y;
                    $x=($x==$this->kothic_gran)?$this->kothic_gran-1:$x;
                    $y=($y==$this->kothic_gran)?$this->kothic_gran-1:$y;
                    if($x>=0 && $y>=0 && $x<=$this->kothic_gran && 
                        $y<=$this->kothic_gran)
                    {
                           $coords[] = array($x,$y);
                    }
                }
                break;

            case "MultiLineString":
            case "Polygon":
                for($i=0; $i<count($f["coordinates"]); $i++)
                {
                    $coords[$i]=array();
                    for($j=0; $j<count($f["coordinates"][$i]); $j++)
                    {
                        $x =  (int)    
                        round(($f["coordinates"][$i][$j][0]-
                        $this->bbox[0]) * $factor);
                        $y =     (int)
                        round(($f["coordinates"][$i][$j][1]-
                        $this->bbox[1]) * $factor);
                        // coords of (0,0) seem to screw up rendering
                        if($f["type"]=="MultiLineString")
                        {
                            $x=($x==0)?1:$x;
                            $y=($y==0)?1:$y;
                            $x=($x==$this->kothic_gran)?$this->kothic_gran-1:$x;
                            $y=($y==$this->kothic_gran)?$this->kothic_gran-1:$y;
                        }
                        $coords[$i][] = array($x,$y);

                    }
                }
                break;

            case "MultiPolygon":
                for($i=0; $i<count($f["coordinates"]); $i++)
                {
                    $coords[$i]=array();
                    for($j=0; $j<count($f["coordinates"][$i]); $j++)
                    {
                        $coords[$i][$j]=array();
                        for($k=0; $k<count($f["coordinates"][$i][$j]); $k++)
                        {
                            $x =  (int)    
                            round(($f["coordinates"][$i][$j][$k][0]-
                            $this->bbox[0]) * 
                            $factor);

                            $y =     (int)
                            round(($f["coordinates"][$i][$j][$k][1]-
                            $this->bbox[1]) * 
                            $factor);

                            $x=($x==0)?1:$x;
                            $y=($y==0)?1:$y;
                            $x=($x==$this->kothic_gran)?$this->kothic_gran-1:$x;
                            $y=($y==$this->kothic_gran)?$this->kothic_gran-1:$y;
                            $coords[$i][$j][] = array($x,$y);
                        }
                    }
                }
                break;
        }
        return $coords;
    }

    function getPOIQuery()
    {
        return parent::getPOIQuery() . " AND ".$this->dbq->poiDetails["col"].
            " && ".$this->geomtxt2;
    }

    function getWayQuery($table)
    {
        return ($table=="polygon") ?
            $this->dbq->getBboxPolygonQuery($this->geomtxt) :
            $this->dbq->getBboxWayQuery($this->geomtxt); 
    }

	function getUniqueList($property)
	{
		$values = array();
		foreach($this->data["features"] as $f)
		{
			if(!in_array($f["properties"][$property],$values))
			{
				$values[] = $f["properties"][$property];
			}
		}
		return $values;
	}

	function simpleGetData()
	{
		return $this->data;
	}
}

?>
