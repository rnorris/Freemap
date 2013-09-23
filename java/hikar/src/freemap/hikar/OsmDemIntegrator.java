package freemap.hikar;

import freemap.data.Projection;
import freemap.datasource.CachedTileDeliverer;
import freemap.datasource.WebDataSource;
import freemap.jdem.DEMSource;
import freemap.jdem.HGTDataInterpreter;
import freemap.jdem.HGTTileDeliverer;
import freemap.datasource.FreemapFileFormatter;
import freemap.data.Point;
import freemap.datasource.XMLDataInterpreter;
import freemap.datasource.FreemapDataHandler;
import freemap.jdem.DEM;
import freemap.proj.LFPFileFormatter;
import freemap.proj.Proj4ProjectionFactory;
import freemap.datasource.FreemapDataset;
import freemap.datasource.Tile;
import freemap.andromaps.GeoJSONDataInterpreter;
import java.io.File;
import java.util.HashMap;
import android.util.Log;



public class OsmDemIntegrator {

	CachedTileDeliverer osm;
	HGTTileDeliverer hgt;
	Projection tilingProj;
	
	public OsmDemIntegrator(String projID)
	{
		WebDataSource hgtDataSource=new WebDataSource("http://www.free-map.org.uk/downloads/lfp/", 
				new LFPFileFormatter());
		
		
		FreemapFileFormatter formatter=new FreemapFileFormatter(projID, "geojson");
        formatter.setScript("bsvr.php");
        formatter.selectWays("highway");
        
        WebDataSource osmDataSource=new WebDataSource("http://www.free-map.org.uk/0.6/ws/",formatter);
        
		Proj4ProjectionFactory factory=new Proj4ProjectionFactory();
		tilingProj = factory.generate(projID);
		File cacheDir = new File(android.os.Environment.getExternalStorageDirectory().getAbsolutePath()+"/hikar/cache/" +
		//File cacheDir = new File("/storage/extSdCard/hikar/cache/" +
		        tilingProj.getID().toLowerCase().replace("epsg:","")+"/");
		if(!cacheDir.exists())
		    cacheDir.mkdirs();
		
		hgt=new HGTTileDeliverer("dem",hgtDataSource, new HGTDataInterpreter(101,101,50,
											DEMSource.LITTLE_ENDIAN),
						5000, 5000, tilingProj,101,101,50,cacheDir.getAbsolutePath());
						
		
		osm = new CachedTileDeliverer("osm",osmDataSource, 
					//new XMLDataInterpreter(new FreemapDataHandler(factory)),
		            new GeoJSONDataInterpreter(),
					5000,5000,
					tilingProj,
					cacheDir.getAbsolutePath());
		
		hgt.setCache(true);
		osm.setCache(true);
		osm.setReprojectCachedData(true);
		
	}
	
	public boolean needNewData(Point point)
	{
	    return osm.needNewData(point) || hgt.needNewData(point);
	}
	
	// ASSUMPTION: the tiling systems for hgt and osm data coincide - which they do here (see constructor)
	public boolean update(Point point) throws Exception
	{
	 Log.d("hikar","Getting DEM data... time=" + System.currentTimeMillis());
	    HashMap<String,Tile>hgtupdated = hgt.doUpdateSurroundingTiles(point);
	   //Log.d("hikar"," DEM returned ");
		
	       
	   Log.d("hikar","Getting OSM data... time=" + System.currentTimeMillis());
		
		HashMap<String,Tile>osmupdated = osm.doUpdateSurroundingTiles(point);
	     Log.d("hikar","Finished getting OSM data... time=" + System.currentTimeMillis());
			    
	    for(HashMap.Entry<String,Tile> e: osmupdated.entrySet())
		{
	        
	        //Log.d("hikar", "DEM projection: " +((DEM)(hgtupdated.get(e.getKey()).data)).getProjection());
			if(hgtupdated.get(e.getKey()) !=null && osmupdated.get(e.getKey()) != null)
			    //&& !e.getValue().isCache)
			
			{
			   android.util.Log.d("hikar","Applying DEM as not applied yet: key=" + e.getKey());
			   
			   FreemapDataset d = (FreemapDataset)e.getValue().data;
			   DEM dem = (DEM)(hgtupdated.get(e.getKey()).data);
	           Log.d("hikar","Applying DEM: time=" + System.currentTimeMillis());
			   d.applyDEM(dem);
			   Log.d("hikar","Not Caching: time=" + System.currentTimeMillis());
			   //osm.cacheByKey(d, e.getKey());
			   //Log.d("hikar","Done");
			   Log.d("hikar","Finished: time=" + System.currentTimeMillis());
			}
			else
			{
			   android.util.Log.d("hikar","osm: is cache, has dem already");
			}
	    }
			
	   Log.d("hikar","update() finished: time=" + System.currentTimeMillis());
		
		return true;
	}
	
	public double getHeight(Point p)
	{
		DEM dem = (DEM) hgt.getData();
		if(dem!=null)
		{
			double h=dem.getHeight(p.x,p.y,tilingProj);
			//Log.d("OpenTrail","Height at: " + p.x+" "+p.y+"="+h+" proj=" + tilingProj);
			return h;
		}
		return -1;
	}
	
	public void forceDownload(Point bottomLeft, Point topRight) throws Exception
	{
		osm.forceDownload(bottomLeft, topRight);
	}
	
	public DEM getCurrentDEM()
	{
		return (DEM)hgt.getData();
	}
	
	public FreemapDataset getCurrentOSMData()
	{
		return (FreemapDataset)osm.getData();
	}
	
	public HGTTileDeliverer getDEM()
    {
        return hgt;
    }
}
