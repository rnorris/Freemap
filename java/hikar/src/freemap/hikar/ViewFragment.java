package freemap.hikar;

import freemap.data.Point;
import freemap.datasource.FreemapDataset;
import freemap.proj.OSGBProjection;
import freemap.data.Projection;
import android.app.Fragment;
import android.app.Activity;
import android.location.Location;
import android.os.Bundle;
import android.view.View;
import android.view.LayoutInflater;
import android.view.ViewGroup;


public class ViewFragment extends Fragment 
    implements LocationProcessor.Receiver,DownloadDataTask.Receiver,
    SensorInput.SensorInputReceiver, PinchListener.Handler {

    OsmDemIntegrator integrator;
    OpenGLView glView;
    DownloadDataTask downloadDataTask;
    LocationProcessor locationProcessor;
    SensorInput sensorInput;
    Projection proj;
    
    
    public ViewFragment()
    {
        proj = new OSGBProjection();
        setRetainInstance(true);
        sensorInput = new SensorInput(this);
        integrator = new OsmDemIntegrator(proj.getID()); 
    
    }
    
    public void onAttach(Activity activity)
    {
        super.onAttach(activity);
        glView = new OpenGLView(activity);  
        sensorInput.attach(activity);
        locationProcessor = new LocationProcessor(activity,this,5000,10);
        glView.setOnTouchListener(new PinchListener(this));
        FreemapDataset data = integrator.getCurrentOSMData();
        setHFOV();
        if(data!=null)
            glView.getRenderer().setRenderData(data);
    }
    
    public void onCreate(Bundle savedInstanceState)
    {
        super.onCreate(savedInstanceState);
 
    }
    
    public View onCreateView(LayoutInflater inflater, ViewGroup group, Bundle savedInstanceState)
    {
        return glView;
    }
    
    public void onResume() {
        super.onResume();
        locationProcessor.startUpdates();
        glView.getRenderer().onResume();
        sensorInput.start();
    }

    public void onPause() {
        super.onPause();
        locationProcessor.stopUpdates();
        sensorInput.stop();
        glView.getRenderer().onPause();
    }

    public void onDetach() {
        super.onDetach();
        sensorInput.detach();
    }
    
    public void receiveLocation(Location loc) {
        Point p = new Point(loc.getLongitude(), loc.getLatitude());
        Point projected = proj.project(p);
        glView.getRenderer().setCameraLocation((float)projected.x, (float)projected.y);
        double height = integrator.getHeight(projected);
        glView.getRenderer().setHeight((float)height);
        ((Hikar)getActivity()).getHUD().setHeight((float)height);
        ((Hikar)getActivity()).getHUD().invalidate();
        if(integrator.needNewData(p))
        {
            downloadDataTask = new DownloadDataTask(this.getActivity(), this, integrator);
            downloadDataTask.setDialogDetails("Loading...", "Loading data...");
            downloadDataTask.execute(p);
        }
    }
    
    public void noGPS() { }
    
    public void receiveData(FreemapDataset data)
    {
        glView.getRenderer().setRenderData(data);
    }
    
    public void receiveSensorInput(float[] matrix, float[] orientation)
    {  
        glView.getRenderer().setOrientMtx(matrix);
        ((Hikar)getActivity()).getHUD().setOrientation(orientation);
        ((Hikar)getActivity()).getHUD().invalidate();
    }
    
    public void onPinchIn()
    {
        glView.getRenderer().changeHFOV(5.0f);
        setHFOV();
    }
     
    public void onPinchOut()
    {
        glView.getRenderer().changeHFOV(-5.0f);
        setHFOV();
    }
    
    private void setHFOV()
    {
        ((Hikar)getActivity()).getHUD().setHFOV(glView.getRenderer().getHFOV());
        ((Hikar)getActivity()).getHUD().invalidate();
    }
    
    public void toggleCalibrate()
    {
        glView.getRenderer().toggleCalibrate();
    }
    
    public void setCameraHeight(float cameraHeight)
    {
        android.util.Log.d("hikar","camera height=" + cameraHeight);
        glView.getRenderer().setCameraHeight(cameraHeight);
    }
}