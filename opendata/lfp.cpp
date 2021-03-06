// LandForm-Panorama DXF to a PostGIS DB

// g++ -std=c++11 -Wall -g -o lfp lfp.cpp Contour.cpp -lproj -lpq
// NB need c++11 for at least std::to_string()

#include "Contour.h"
#include "Projections.h"
#include <iostream>
#include <cstdio>
#include <cstring>
#include <cstdlib>
using std::cout;
using std::cerr;
using std::endl;
using std::vector;

#include <sstream>

#include <postgresql/libpq-fe.h>

vector<Contour> read_dxf(const char *dxffile,int);
Contour read_contour(FILE *fp,int);

struct OPTIONS
{
    bool sqlstdout, osm;
    int reproj;
    long nodeindex, wayindex;
    std::string dxffile;
    std::string dbuser;
    std::string dbname;
    std::string dbtable;
    uint dbport;
    std::string dbway;
    std::string dbheight;
};

void process_cmd_line(int argc, char *argv[], OPTIONS *options);

int main (int argc, char *argv[])
{
    OPTIONS options = { false, false, 0, 2000000000L, 1000000000L, "" };

    process_cmd_line(argc,argv,&options);


    if(options.dxffile=="")
    {
        cerr<<"Usage: lfp [-g] [-l] [-s] [-o] [-n nodeid] [-w wayid] [-u dbusername] [-d dbname] [-p dbport] [-t dbtable] [-y wayname -h heightname"
            << "LFPfile" << endl <<
              "    -g: Reproject to 'Google' Spherical Mercator" << endl<<
              "    -l: Reproject to WGS84 lat/lon" << endl<<
              "    -u: PG username (otherwise defaults to 'gisuser')" << endl<<
              "    -d: PG database name (otherwise defaults to 'gis')" << endl<<
              "    -t: PG table name (otherwise defaults to 'contours')" << endl<<
              "    -p: PG connection port (otherwise defaults to the system default)" << endl<<
              "    -y: PG geometry way field name (otherwise defaults to the name 'way')" << endl<<
              "    -h: PG height field name (otherwise defaults to the name'height')" << endl<<
              "    -o: Output OSM, not PostGIS SQL" << endl<<
              "    -s: SQL to stdout only (not to DB)" << endl;
        exit(1);
    }


    vector<Contour> contours = read_dxf(options.dxffile.c_str(),options.reproj);

    PGconn *conn;


    int srs[] = { 27700, 900913, 4326 };

    if(!options.sqlstdout && !options.osm)
    {
        // Defaults to "user=gisuser dbname=gis" if not otherwise specified
        std::string dbuser = options.dbuser.empty() ? "gisuser" : options.dbuser;
        std::string dbname = options.dbname.empty() ? "gis" : options.dbname;
        std::string dbport = options.dbport ? "port=" + std::to_string(options.dbport) : "";
        std::string connection = "user=" + dbuser + " dbname=" + dbname + dbport;
        conn = PQconnectdb(connection.c_str());
    }
    if(options.sqlstdout || options.osm || PQstatus(conn)!=CONNECTION_BAD)
    {
        std::string dbtable = options.dbtable.empty() ? "contours" : options.dbtable;
        std::string dbway = options.dbway.empty() ? "way" : options.dbway;
        std::string dbheight = options.dbheight.empty() ? "height" : options.dbheight;
        uint i=0;
        for(i=0; i<contours.size(); i++)
        {
            if(options.osm)
            {
                if(!i)
                    cout<<"<osm version='0.6'>"<<endl;
                cout << contours[i].toOSM(options.wayindex + i,
                    options.nodeindex);
                options.nodeindex+=contours[i].nPoints();
            }
            else
            {
                string wkt=contours[i].toWKT();
                std::stringstream ss;
                ss << "INSERT INTO " << dbtable << " (" << dbheight << "," << dbway << ") VALUES ("
                    << contours[i].getHeight() << ","
                    <<"GeomFromText('" << wkt << "', " 
                    << srs[options.reproj]<<"));";
                //cout << ss.str()<<endl;
                if(options.sqlstdout)
                    cout<<ss.str().c_str()<<endl;
                else
                    PQexec(conn,ss.str().c_str());
            }
        }
        if(options.osm && i>0) cout << "</osm>";
    }
    else
    {
        fprintf(stderr,"Error: %s\n", PQerrorMessage(conn));
    }
    if(!options.sqlstdout && !options.osm)
        PQfinish(conn);
    return 0;    
}

vector<Contour> read_dxf(const char *dxffile,  int reproj)
{
    char buf[1024];
    vector<Contour> result;
    FILE *fp=fopen(dxffile,"r");
    if(fp!=NULL)
    {
        while(fgets(buf,1024,fp))
        {
            if(!strncmp(buf,"POLYLINE",8))
            {
                while(strncmp(buf,"G8040201",8))
                {
                    fgets(buf,1024,fp);
                    if(!strncmp(buf,"SEQEND",6))
                        break;
                }

                if(!strncmp(buf,"G8040201",8))
                    result.push_back(read_contour(fp,reproj));
            }
        }    
    }
    else
    {
        cerr<<"Cannot open: " << dxffile << endl;
    }
    return result;
}

Contour read_contour(FILE *fp,  int reproj)
{
    char buf[1024];
    double x,y,z;
    while(strncmp(buf," 30",3))
        fgets(buf,1024,fp);
    fgets(buf,1024,fp);
    z=atof(buf);
    Contour contour(z);


    for(;;)
    {

        while(strncmp(buf,"VERTEX",6))
        {
            fgets(buf,1024,fp);
            if(!strncmp(buf,"SEQEND",6))
            {
                return contour;
            }
        }
    
        while(strncmp(buf," 10",3))
            fgets(buf,1024,fp);

        fgets(buf,1024,fp);
        x = atof(buf);

        while(strncmp(buf," 20",3))
            fgets(buf,1024,fp);

        fgets(buf,1024,fp);
        y = atof(buf);
    
        if(reproj==1)
            pj_transform(Projections::osgb,Projections::goog,1,1,&x,&y,NULL);    
        else if (reproj==2)
        {
            pj_transform(Projections::osgb,Projections::lonlat,1,1,&x,&y,NULL);
            x *= 180.0 / M_PI;
            y *= 180.0 / M_PI;
        }
    
        contour.addPoint(x,y);
    }
}

void process_cmd_line(int argc, char *argv[], OPTIONS *options)
{
    while(argc>2)
    {
        if(!strcmp(argv[1],"-g"))
        {
            (argc)--;
            argv++;
            options->reproj=1;    
        }
        else if (!strcmp(argv[1],"-l"))
        {
            argc--;
            argv++;
            options->reproj=2;
        }

        else if(!strcmp(argv[1],"-s"))
        {
            options->sqlstdout=true;
            argc--;
            argv++;
        }

        else if(!strcmp(argv[1],"-o"))
        {
            options->osm=true;
            argc--;
            argv++;
        }

        else if(!strcmp(argv[1],"-n"))
        {
            options->nodeindex = atol(argv[2]);
            argc-=2;
            argv+=2;
        }

        else if(!strcmp(argv[1],"-w"))
        {
            options->wayindex = atol(argv[2]);
            argc-=2;
            argv+=2;
        }

        else if(!strcmp(argv[1],"-u"))
        {
            options->dbuser = argv[2];
            argc-=2;
            argv+=2;
        }

        else if(!strcmp(argv[1],"-d"))
        {
            options->dbname = argv[2];
            argc-=2;
            argv+=2;
        }

        else if(!strcmp(argv[1],"-t"))
        {
            options->dbtable = argv[2];
            argc-=2;
            argv+=2;
        }

        else if(!strcmp(argv[1],"-p"))
        {
            options->dbport = atoi(argv[2]);
            argc-=2;
            argv+=2;
        }

        else if(!strcmp(argv[1],"-y"))
        {
            options->dbway = argv[2];
            argc-=2;
            argv+=2;
        }

        else if(!strcmp(argv[1],"-h"))
        {
            options->dbheight = argv[2];
            argc-=2;
            argv+=2;
        }

        else
        {
            argc--;
            argv++;
        }
    }

    if(argc==2)
        options->dxffile = argv[1];
}
