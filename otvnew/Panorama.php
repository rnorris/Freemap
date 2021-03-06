<?php
// Class to represent a submitted panorama
// A submitted panorama differs from a plain photosphere:
// it is a specific entity for opentrailview and includes associated database 
// data, etc.

require_once('../common/defines.php');

class Panorama
{
    private $id, $lon, $lat;

    function __construct ($id)
    {
        $this->id = $id;    
    }


    function show($w, $h)
    {
        if($this->getDBData()!==false)
        {
            $file = OTV_UPLOADS . "/". $this->id.".jpg";
            if(file_exists($file))
            {
                list($fullw, $fullh, $type) =  getimagesize($file);
                $im = ImageCreateFromJPEG($file);
                $im_rsz = ImageCreateTrueColor($w, $h);
                ImageCopyResized($im_rsz, $im, 0, 0, 0, 0, $w,$h,$fullw,$fullh);
                header("Content-type: image/jpg");
                ImageJPEG($im_rsz);
                ImageDestroy($im_rsz);
                ImageDestroy($im);
                return true;
            }
            return false;
        }
        else
            echo "no db data";
        return false;
    }
    
    function getDBData()
    {
        $result=pg_query
            ("SELECT * FROM panoramas WHERE id=".$this->id );
        return pg_num_rows($result)==1 ?
            pg_fetch_array ($result, null, PGSQL_ASSOC) : false;
    }

    function getId()
    {
        return $this->id;
    }

    function getLat()
    {
        return $this->lat;
    }

    function getLon()
    {
        return $this->lon;
    }

    function authorise()
    {
        pg_query("UPDATE panoramas SET authorised=1 WHERE id=".$this->id);
        rename ( OTV_UPLOADS . "/" . $this->id .".jpg",
                     OTV_UPLOADS . "/authorised/" . $this->id .".jpg");
    }

    function del()
    {
        pg_query("DELETE FROM panoramas WHERE id=".$this->id);
        unlink (OTV_UPLOADS . "/". $this->id . ".jpg");
    }

    static function getNearest ($lon, $lat)
    {
        $sm = reproject ($lon, $lat, "4326", "900913");
        $result=pg_query
            ("SELECT * FROM panoramas WHERE authorised=1 ORDER BY ".
                "Distance(GeomFromText('POINT($sm[0] $sm[1])',900913),xy) ".
                "LIMIT 1");
        if(pg_num_rows($result)==1)
        {
            $row=pg_fetch_array($result, null, PGSQL_ASSOC);
            return new Panorama($row["id"]);
        }
        return false;
    }

    static function getWithinBbox($bbox)
    {
        list($w,$s) = reproject($bbox[0],$bbox[1],"4326","900913");
        list($e,$n) = reproject($bbox[2],$bbox[3],"4326","900913");

        $g="GeomFromText('POLYGON(($w $s,$e $s,$e $n,$w $n,$w $s))',900913)";

        $q="SELECT id,astext(xy) " .
            "FROM panoramas WHERE authorised=1 AND xy && $g";
    

        $panos = array();

        $result = pg_query($q);

        while($row=pg_fetch_array($result,null,PGSQL_ASSOC))
        {
            if(file_exists(OTV_LIVE_PANOS . "/". $row["id"] . ".jpg"))
            {
                $panos[] = Panorama::loadFromRow($row);
            }
        }
        return $panos;
    }

    static function loadFromRow($row)
    {
        $pano = new Panorama ($row["id"]);
        $m=array();
        preg_match("/POINT\((.+)\)/",$row['astext'],$m);
        $p = explode(" ", $m[1]);
        list($pano->lon,$pano->lat) = reproject($p[0],$p[1],"900913","4326");
        return $pano;
    }

    static function getUnmoderated()
    {
        $nonauth = array();
        $result = pg_query ("SELECT * FROM panoramas WHERE authorised=0");
        while($row=pg_fetch_array($result, null, PGSQL_ASSOC))
            $nonauth[] = $row["id"];
        return $nonauth;
    }
}
