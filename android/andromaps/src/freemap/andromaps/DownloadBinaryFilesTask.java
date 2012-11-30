package freemap.andromaps;


import java.io.BufferedInputStream;
import java.io.BufferedReader;
import java.io.FileWriter;
import java.io.InputStreamReader;
import java.io.PrintWriter;
import java.io.InputStream;
import java.io.IOException;

import android.content.Context;
import freemap.andromaps.DownloadFilesTask.Callback;

import java.nio.channels.FileChannel;

import java.io.BufferedOutputStream;
import java.io.FileOutputStream;

public class DownloadBinaryFilesTask extends DownloadFilesTask {

	public DownloadBinaryFilesTask(Context ctx,  String[] urls, String[] localFiles, String alertMsg, Callback callback, 
			int taskId)
	{
		super(ctx,urls,localFiles,alertMsg,callback,taskId);
	}
	
	public void doWriteFile(InputStream in, String outputFile) throws IOException
	{
		FileOutputStream out=new FileOutputStream(outputFile);
		byte[] data = new byte[1024*64];
		int nRead;
		while((nRead=in.read(data)) != -1)
			out.write(data,0,nRead);
		out.close();
	}
}

